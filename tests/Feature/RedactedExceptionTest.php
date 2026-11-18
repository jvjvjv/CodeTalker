<?php

namespace Jvjvjv\CodeTalker\Tests\Feature;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Jvjvjv\CodeTalker\Support\RedactedException;
use Jvjvjv\CodeTalker\Tests\TestCase;

class RedactedExceptionTest extends TestCase
{
    public function test_a_transport_failure_keeps_its_message_with_credentials_masked(): void
    {
        $context = RedactedException::context(new ConnectionException(
            'cURL error 7: Failed to connect for https://user:p@ss@internal.test:8443/v1?api_key=k-secret'
        ));

        $this->assertSame([
            'exception' => ConnectionException::class,
            'error' => 'cURL error 7: Failed to connect for https://internal.test:8443/v1?api_key=***',
        ], $context);
    }

    public function test_a_response_error_is_reduced_to_its_status(): void
    {
        $response = new Response(new Psr7Response(401, [], '{"error":"bad key sk-echoed"}'));

        $this->assertSame(
            ['exception' => RequestException::class, 'status' => 401],
            RedactedException::context(new RequestException($response)),
        );
    }

    public function test_any_other_exception_is_reduced_to_its_class(): void
    {
        $this->assertSame(
            ['exception' => \RuntimeException::class],
            RedactedException::context(new \RuntimeException('token sk-anything for https://host.test/?q=1')),
        );
    }
}
