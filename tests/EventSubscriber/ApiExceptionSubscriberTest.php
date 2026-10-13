<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ApiExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

class ApiExceptionSubscriberTest extends TestCase
{
    public function testUnexpectedErrorBecomesGenericJson500WithoutDetailsOutsideDebug(): void
    {
        $event = $this->dispatch(new ApiExceptionSubscriber(debug: false), '/api/wallets', new RuntimeException('SQLSTATE secret'));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Internal Server Error'], json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDebugModeIncludesExceptionMessage(): void
    {
        $event = $this->dispatch(new ApiExceptionSubscriber(debug: true), '/api/wallets', new RuntimeException('SQLSTATE secret'));

        $data = json_decode($event->getResponse()?->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('SQLSTATE secret', $data['detail']);
    }

    public function testHttpExceptionKeepsStatusCodeAndHeaders(): void
    {
        $event = $this->dispatch(
            new ApiExceptionSubscriber(debug: false),
            '/api/wallets/abc/deposit',
            new MethodNotAllowedHttpException(['POST']),
        );

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->headers->get('Allow'));
        self::assertSame(['error' => 'Method Not Allowed'], json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testNonApiPathIsLeftToSymfony(): void
    {
        $event = $this->dispatch(new ApiExceptionSubscriber(debug: false), '/', new NotFoundHttpException());

        self::assertNull($event->getResponse());
    }

    private function dispatch(ApiExceptionSubscriber $subscriber, string $path, Throwable $exception): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        $subscriber->onKernelException($event);

        return $event;
    }
}
