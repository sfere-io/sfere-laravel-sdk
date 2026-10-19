<?php

namespace Sfere\Payload;

use Closure;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Sfere\Context\ContextMerger;
use Sfere\Context\CurrentRequest;
use Sfere\Context\RequestContext;
use Sfere\Exceptions\InvalidEventException;
use Sfere\Support\Library;
use Sfere\Support\Settings;

/**
 * Builds the wire event (WIRE-CONTRACT §3, §4) at call time, so the customer's request context is
 * captured even when the event is sent later from a buffer or a queue worker.
 */
final class EventFactory
{
    /** Envelope keys allowed on the wire, in the order they are written. */
    private const ENVELOPE = [
        'type', 'messageId', 'userId', 'anonymousId', 'timestamp',
        'event', 'name', 'properties', 'traits', 'groupId', 'previousId',
        'context', 'integrations',
    ];

    /** Type-specific fields each event type may carry. */
    private const FIELDS = [
        'track' => ['event', 'properties'],
        'identify' => ['traits'],
        'page' => ['name', 'properties'],
        'group' => ['groupId', 'traits'],
        'alias' => ['previousId'],
    ];

    /**
     * @param  (Closure(): DateTimeInterface)|null  $clock
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly CurrentRequest $currentRequest,
        private readonly string $frameworkVersion,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * @param  string  $type  track | identify | page | group | alias
     * @param  array<string, mixed>  $fields  the type-specific fields (event, properties, traits, name, groupId, previousId)
     * @param  array<string, mixed>  $context  per-call context
     * @param  array<string, mixed>|null  $integrations
     * @return array<string, mixed>
     *
     * @throws InvalidEventException
     */
    public function make(
        string $type,
        array $fields,
        Scope $scope,
        ?string $userId,
        ?string $anonymousId,
        ?DateTimeInterface $timestamp,
        array $context,
        string $messageId,
        ?array $integrations = null,
    ): array {
        if (! isset(self::FIELDS[$type])) {
            throw InvalidEventException::because($type, 'unsupported type (use track, identify, page, group or alias)');
        }

        $request = $this->currentRequest->get();

        $anonymousId ??= $scope->anonymousId;
        if ($anonymousId === null && $request !== null && $this->settings->anonymousIdCookie !== null) {
            $anonymousId = RequestContext::anonymousId($request, $this->settings->anonymousIdCookie);
        }

        $event = [
            'type' => $type,
            'messageId' => $messageId,
            'userId' => $userId ?? $scope->userId,
            'anonymousId' => $anonymousId,
            'timestamp' => self::formatTimestamp($timestamp ?? $this->now()),
            'context' => $this->buildContext($request, $scope, $context),
        ];

        foreach (self::FIELDS[$type] as $field) {
            if (array_key_exists($field, $fields) && $fields[$field] !== null) {
                $event[$field] = $fields[$field];
            }
        }

        if ($type === 'track') {
            $properties = is_array($event['properties'] ?? null) ? $event['properties'] : [];
            if (($properties['sfere_dedup_id'] ?? null) === null) {
                $properties['sfere_dedup_id'] = $messageId;
            }
            $event['properties'] = $properties;
        }

        if ($type === 'identify' && $scope->traits !== []) {
            $explicit = is_array($event['traits'] ?? null) ? $event['traits'] : [];
            $event['traits'] = ContextMerger::merge($scope->traits, $explicit);
        }

        if ($integrations !== null && $integrations !== []) {
            $event['integrations'] = $integrations;
        }

        $event = self::order($event);

        Validator::validate($event);

        return $event;
    }

    /**
     * `Sfere::requestContext()`: the context of the given (or current) request, or `[]` outside one.
     *
     * @return array<string, mixed>
     */
    public function requestContext(?Request $request = null, ?string $pageUrl = null): array
    {
        $request ??= $this->currentRequest->get();

        return $request === null ? [] : RequestContext::fromRequest($request, $this->settings->trustProxy, $pageUrl);
    }

    public static function formatTimestamp(DateTimeInterface $time): string
    {
        return Carbon::instance($time)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Merge order (later wins): static config, request context, resolver traits, scoped context,
     * per-call context; `library` is always this SDK's.
     *
     * @param  array<string, mixed>  $callContext
     * @return array<array-key, mixed>
     */
    private function buildContext(?Request $request, Scope $scope, array $callContext): array
    {
        $fromRequest = $request !== null && $this->settings->includeRequest
            ? RequestContext::fromRequest($request, $this->settings->trustProxy)
            : [];

        $context = ContextMerger::merge(
            $this->settings->staticContext,
            $fromRequest,
            $scope->traits !== [] ? ['traits' => $scope->traits] : [],
            $scope->context,
            $callContext,
        );

        $context['library'] = Library::context($this->frameworkVersion);

        return $context;
    }

    /**
     * Envelope keys in a stable order; nulls, empty `properties` / `traits` (which PHP would encode
     * as a JSON list) and unknown keys are dropped.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private static function order(array $event): array
    {
        $ordered = [];
        foreach (self::ENVELOPE as $key) {
            if (! array_key_exists($key, $event) || $event[$key] === null) {
                continue;
            }
            if (in_array($key, ['properties', 'traits'], true) && $event[$key] === []) {
                continue;
            }
            $ordered[$key] = $event[$key];
        }

        return $ordered;
    }

    private function now(): DateTimeInterface
    {
        return $this->clock !== null ? ($this->clock)() : Carbon::now();
    }
}
