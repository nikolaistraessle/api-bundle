<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Webkul\TicketBundle\Entity\Ticket;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;

use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class Threads extends Controller
{
    /** Ticket Reply
     * @param Request $request
     */
    public function saveThread(Request $request, $ticketid)
    {
        $data = $request->request->all()? : json_decode($request->getContent(),true);

        if (!isset($data['threadType']) || !isset($data['message'])) {
            $json['error'] = 'missing fields';
            $json['description'] = 'required: threadType: reply|forward|note , message';
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        $ticket = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:Ticket')->findOneById($ticketid);

        // Check for empty ticket
        if (empty($ticket)) {
            $json['error'] = "Error! No such ticket with ticket id exist";
            return new JsonResponse($json, Response::HTTP_NOT_FOUND);
        } else if ('POST' != $request->getMethod()) {
            $json['error'] = "Error! invalid request method";
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        // Check if message content is empty
        $parsedMessage = trim(strip_tags($data['message'], '<img>'));
        $parsedMessage = str_replace('&nbsp;', '', $parsedMessage);
        $parsedMessage = str_replace(' ', '', $parsedMessage);

        if (null == $parsedMessage) {
            $json['error'] = "Warning ! Reply content cannot be left blank.";
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('actAsType', $data) && isset($data['actAsEmail'])) {
            $actAsType = strtolower($data['actAsType']);
            $actAsEmail = $data['actAsEmail'];
            if ($actAsType == 'customer') {
                $customer = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:User')->findOneByEmail($data['actAsEmail']);
            } else if($actAsType == 'agent' ) {
                $user = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:User')->findOneByEmail($data['actAsEmail']);
            } else {
                $json['error'] = 'Error! invalid actAs details.';
                $json['description'] = 'possible values actAsType: customer,agent. Also provide actAsEmail parameter with actAsType agent.';
                return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
            }                                      
            if (!$user) {
                $json['error'] = 'Error! invalid actAs details.';
                return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
            }
        } 

        if ($actAsType == 'agent') {
            $data['user'] = isset($user) && $user ? $user : $this->get('user.service')->getCurrentUser();
        } else {
            $data['user'] = $customer;
        }

        $threadDetails = [
            'user' => $data['user'],
            'createdBy' => $actAsType,
            'source' => 'api',
            'threadType' => strtolower($data['threadType']),
            'message' => str_replace(['&lt;script&gt;', '&lt;/script&gt;'], '', $data['message']),
            'attachments' => $request->files->get('attachments')
        ];

        if (!empty($data['status'])){
            $ticketStatus =  $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:TicketStatus')->findOneByCode($data['status']);
            $ticket->setStatus($ticketStatus);
        }
        if (isset($data['to'])) {
            $threadDetails['to'] = $data['to'];
        }

        if (isset($data['cc'])) {
            $threadDetails['cc'] = $data['cc'];
        }

        if (isset($data['cccol'])) {
            $threadDetails['cccol'] = $data['cccol'];
        }

        if (isset($data['bcc'])) {
            $threadDetails['bcc'] = $data['bcc'];
        }

        // Create Thread
        $thread = $this->get('ticket.service')->createThread($ticket, $threadDetails);

        // Check for thread types
        switch ($thread->getThreadType()) {
            case 'note':
                $event = new GenericEvent(CoreWorkflowEvents\Ticket\Note::getId(), [
                    'entity' =>  $ticket,
                    'thread' =>  $thread
                ]);
                $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);

                $json['success'] = "success', Note added to ticket successfully.";
                return new JsonResponse($json, Response::HTTP_OK);
                break;
            case 'reply':
                $event = new GenericEvent(CoreWorkflowEvents\Ticket\AgentReply::getId(), [
                    'entity' =>  $ticket,
                    'thread' =>  $thread
                ]);
                $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);

                $json['success'] = "success', Reply added to ticket successfully..";
                return new JsonResponse($json, Response::HTTP_OK);
                break;
            case 'forward':
                // Prepare headers
                $headers = ['References' => $ticket->getReferenceIds()];

                if (null != $ticket->currentThread->getMessageId()) {
                    $headers['In-Reply-To'] = $ticket->currentThread->getMessageId();
                }

                // Prepare attachments
                $attachments = $entityManager->getRepository(Attachment::class)->findByThread($thread);

                $projectDir = $this->get('kernel')->getProjectDir();
                $attachments = array_map(function($attachment) use ($projectDir) {
                return str_replace('//', '/', $projectDir . "/public" . $attachment->getPath());
                }, $attachments);

                // Forward thread to users
                try {
                    $messageId = $this->get('email.service')->sendMail($params['subject'] ?? ("Forward: " . $ticket->getSubject()), $thread->getMessage(), $thread->getReplyTo(), $headers, $ticket->getMailboxEmail(), $attachments ?? [], $thread->getCc() ?: [], $thread->getBcc() ?: []);
    
                    if (!empty($messageId)) {
                        $thread->setMessageId($messageId);
    
                        $entityManager->persist($createdThread);
                        $entityManager->flush();
                    }
                } catch (\Exception $e) {
                    // Do nothing ...
                    // @TODO: Log exception
                }

                $json['success'] = "success', Reply added to the ticket and forwarded successfully.";
                return new JsonResponse($json, Response::HTTP_OK);
                break;
            default:
                break;
        }
    }
}
