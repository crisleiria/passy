<?php

namespace App\Support;

use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

class JsonSerializer
{

    /**
     * @throws ExceptionInterface
     */
    public static function serialize(object $data) : string
    {
        return new WebauthnSerializerFactory(AttestationStatementSupportManager::create())
            ->create()
            ->serialize($data, 'json');
    }

    /**
     * Deserialize a JSON string into an object.
     *
     * @template TReturn
     * @param string $json
     * @param class-string<TReturn> $into
     * @return TReturn
     * @throws ExceptionInterface
     */
    public static function deserialize(string $json, string $into)
    {
        return new WebauthnSerializerFactory(AttestationStatementSupportManager::create())
            ->create()
            ->deserialize($json, $into, 'json');
    }


}
