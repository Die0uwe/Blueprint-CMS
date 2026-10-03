<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Http;

/**
 * Naad tussen providers en het netwerk. Productie: CurlTransport.
 * Tests: een nep-implementatie, zodat er nooit echt netwerkverkeer is.
 */
interface HttpTransportInterface
{
    /**
     * Stuur de request en geef de body in brokken terug zodra ze binnenkomen.
     *
     * @return iterable<string>
     * @throws TransportException bij netwerkfouten en niet-2xx antwoorden
     */
    public function stream(HttpRequest $request): iterable;

    /**
     * Eenvoudige request/response (bv. key-validatie).
     *
     * @throws TransportException bij netwerkfouten
     */
    public function send(HttpRequest $request): HttpResponse;
}
