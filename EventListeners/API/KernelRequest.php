<?php

namespace Webkul\UVDesk\ApiBundle\EventListeners\API;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;

class KernelRequest
{
    private $firewall;

    public function __construct(FirewallMap $firewall)
    {
        $this->firewall = $firewall;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }
        
        $request = $event->getRequest();
        $method  = $request->getRealMethod();
        if ('OPTIONS' == $method) {
            $event->setResponse(new Response());
        }

        return;
    }
}
