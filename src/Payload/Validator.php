<?php

namespace Sfere\Payload;

use Sfere\Exceptions\InvalidEventException;

/**
 * The wire rules of WIRE-CONTRACT §3 and the identity contract, checked before an event is
 * buffered. Error messages never include trait or property values.
 */
final class Validator
{
    public const MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public const MAX_EVENT_NAME_LENGTH = 128;

    public const MAX_ID_LENGTH = 512;

    /** Ingestion rejects events over 1 MB after wrapping them; leave headroom. */
    public const MAX_EVENT_BYTES = 900_000;

    /** Placeholder ids the identity contract forbids (compared case-insensitively). */
    public const FORBIDDEN_USER_IDS = ['0', '-1', 'null', 'undefined', 'test', 'na', 'n/a'];

    /**
     * @param  array<string, mixed>  $event
     *
     * @throws InvalidEventException
     */
    public static function validate(array $event): void
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : 'unknown';

        $messageId = $event['messageId'] ?? null;
        if (! is_string($messageId) || preg_match(self::MESSAGE_ID_PATTERN, $messageId) !== 1) {
            throw InvalidEventException::because($type, 'messageId must match [A-Za-z0-9._-] and be 1-64 characters');
        }

        self::checkId($type, 'userId', $event['userId'] ?? null, forbidPlaceholders: true);
        self::checkId($type, 'anonymousId', $event['anonymousId'] ?? null, forbidPlaceholders: false);

        if (! isset($event['userId']) && ! isset($event['anonymousId'])) {
            throw InvalidEventException::because($type, 'a userId or an anonymousId is required');
        }

        switch ($type) {
            case 'track':
                $name = $event['event'] ?? null;
                if (! is_string($name) || trim($name) === '') {
                    throw InvalidEventException::because($type, 'an event name is required');
                }
                if (mb_strlen($name) > self::MAX_EVENT_NAME_LENGTH) {
                    throw InvalidEventException::because($type, 'the event name is longer than 128 characters');
                }
                break;
            case 'group':
                if (! isset($event['groupId']) || $event['groupId'] === '') {
                    throw InvalidEventException::because($type, 'a groupId is required');
                }
                break;
            case 'alias':
                if (! isset($event['previousId']) || $event['previousId'] === '') {
                    throw InvalidEventException::because($type, 'a previousId is required');
                }
                if (! isset($event['userId'])) {
                    throw InvalidEventException::because($type, 'a userId is required');
                }
                break;
        }

        $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw InvalidEventException::because($type, 'the event cannot be encoded as JSON ('.json_last_error_msg().')');
        }
        if (strlen($json) > self::MAX_EVENT_BYTES) {
            throw InvalidEventException::because($type, 'the event is larger than 900 KB');
        }
    }

    private static function checkId(string $type, string $field, mixed $value, bool $forbidPlaceholders): void
    {
        if ($value === null) {
            return;
        }
        if (! is_string($value) || $value === '') {
            throw InvalidEventException::because($type, "{$field} must be a non-empty string");
        }
        if (mb_strlen($value) > self::MAX_ID_LENGTH) {
            throw InvalidEventException::because($type, "{$field} is longer than 512 characters");
        }
        if ($forbidPlaceholders && in_array(strtolower(trim($value)), self::FORBIDDEN_USER_IDS, true)) {
            throw InvalidEventException::because($type, "{$field} is a placeholder value; use the stable internal user id");
        }
    }
}
