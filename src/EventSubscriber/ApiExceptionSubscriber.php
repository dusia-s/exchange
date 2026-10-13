<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns uncaught exceptions on /api routes into JSON errors instead of HTML error pages.
 * Internal details are only included in debug mode.
 */
final readonly class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private bool $debug,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the security listener (1) and Symfony's error logging (0), before its HTML error rendering (-128).
        return [KernelEvents::EXCEPTION => ['onKernelException', -64]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $isHttpException = $exception instanceof HttpExceptionInterface;
        $statusCode = $isHttpException ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;

        $body = ['error' => Response::$statusTexts[$statusCode] ?? 'Error'];
        if ($this->debug) {
            $body['detail'] = $exception->getMessage();
        }

        $event->setResponse(new JsonResponse(
            $body,
            $statusCode,
            $isHttpException ? $exception->getHeaders() : [],
        ));
    }
}
