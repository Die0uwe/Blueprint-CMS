<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests\Support;

use CommunityFusion\Modules\AiStudio\Http\HttpRequest;
use CommunityFusion\Modules\AiStudio\Http\HttpResponse;
use CommunityFusion\Modules\AiStudio\Http\HttpTransportInterface;
use CommunityFusion\Modules\AiStudio\Http\TransportException;

/**
 * Nep-HTTP-laag: nooit echt netwerk. Registreert elke request en levert
 * vooraf ingestelde brokken, een fout of een send()-antwoord.
 */
final class FakeTransport implements HttpTransportInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];
    /** @var list<string> */
    public array $chunks = [];
    public ?TransportException $streamError = null;
    public HttpResponse $sendResponse;
    public ?TransportException $sendError = null;

    public function __construct()
    {
        $this->sendResponse = new HttpResponse(200, '{}');
    }

    /**
     * @param list<string> $chunks
     */
    public static function withChunks(array $chunks): self
    {
        $t = new self();
        $t->chunks = $chunks;
        return $t;
    }

    public function stream(HttpRequest $request): iterable
    {
        $this->requests[] = $request;
        foreach ($this->chunks as $chunk) {
            yield $chunk;
        }
        if ($this->streamError !== null) {
            throw $this->streamError;
        }
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if ($this->sendError !== null) {
            throw $this->sendError;
        }
        return $this->sendResponse;
    }
}
