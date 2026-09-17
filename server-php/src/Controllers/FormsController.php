<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\BookingService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Booking forms: what a client is asked, and what they answered.
 *
 * ## The answers belong to the appointment
 *
 * "Have you had this treatment before", "any allergies", "what would you like
 * to discuss" — these are appointment-workflow facts and they live here. They
 * are not contact details, so they do not belong in Contacts, and they are not
 * a relationship, so they do not belong in CRM.
 *
 * ## Sensitive forms are read separately
 *
 * A form marked `is_sensitive` has its answers hidden from anyone without
 * `appointments.clients.manage`. Everybody who can see the diary can see THAT
 * a form was completed; only the people who need the content can read it. A
 * receptionist needs to know the intake form is done, not what is on it.
 */
final class FormsController extends Controller
{
    /** @var list<string> */
    private const FIELD_TYPES = [
        'text', 'textarea', 'select', 'radio', 'checkbox',
        'date', 'number', 'phone', 'email', 'consent',
    ];

    public static function index(): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        $rows = Db::all(
            'SELECT f.*,
                    (SELECT COUNT(*) FROM appointment_form_fields ff WHERE ff.form_uuid = f.form_uuid) AS field_count,
                    (SELECT COUNT(*) FROM appointment_services s WHERE s.form_uuid = f.form_uuid) AS service_count
               FROM appointment_forms f
              WHERE f.cmp_id = :cmp
                ' . (Http::param('include_inactive') !== null ? '' : 'AND f.is_active = TRUE') . '
              ORDER BY f.name',
            ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'forms'       => array_map([self::class, 'shapeForm'], $rows),
            'field_types' => self::FIELD_TYPES,
        ]);
    }

    public static function show(string $formUuid): void
    {
        [, $ctx] = self::enter('appointments.booking.view');

        $form = Db::first(
            'SELECT * FROM appointment_forms WHERE form_uuid = :id AND cmp_id = :cmp',
            ['id' => $formUuid, 'cmp' => $ctx->cmpId],
        );

        if ($form === null) {
            Http::notFound('That form does not exist.');
        }

        Http::data([
            'form'   => self::shapeForm($form),
            'fields' => array_map([self::class, 'shapeField'], Db::all(
                'SELECT * FROM appointment_form_fields WHERE form_uuid = :id AND cmp_id = :cmp ORDER BY sort_order, label',
                ['id' => $formUuid, 'cmp' => $ctx->cmpId],
            )),
        ]);
    }

    /**
     * Create or replace a form and its fields in one go.
     *
     * Whole-form, not field-by-field. A form builder sends the form it wants;
     * reconciling individual field edits client-side is how a required field
     * ends up orphaned from the form it belonged to.
     */
    public static function save(): void
    {
        [$auth, $ctx] = self::enter('appointments.forms.manage');

        $body = Http::body();
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            Http::validationFailed('A form name is required.');
        }

        $formUuid = trim((string) ($body['form_uuid'] ?? ''));
        $creating = !Uuid::isValid($formUuid);

        if (!$creating) {
            $existing = Db::first(
                'SELECT form_uuid FROM appointment_forms WHERE form_uuid = :id AND cmp_id = :cmp',
                ['id' => $formUuid, 'cmp' => $ctx->cmpId],
            );
            if ($existing === null) {
                Http::notFound('That form does not exist.');
            }
        } else {
            $formUuid = Uuid::v4();
        }

        $fields = [];
        foreach ((array) ($body['fields'] ?? []) as $index => $field) {
            if (!is_array($field)) {
                continue;
            }
            $label = trim((string) ($field['label'] ?? ''));
            $type = strtolower(trim((string) ($field['field_type'] ?? 'text')));

            if ($label === '') {
                Http::validationFailed('Every field needs a label.', ['fields' => 'Field ' . ($index + 1) . ' has no label.']);
            }
            if (!in_array($type, self::FIELD_TYPES, true)) {
                Http::validationFailed('Unknown field type "' . $type . '".', ['fields' => 'Allowed: ' . implode(', ', self::FIELD_TYPES) . '.']);
            }

            $options = array_values(array_filter(array_map(
                static fn ($o) => is_scalar($o) ? trim((string) $o) : '',
                (array) ($field['options'] ?? []),
            ), static fn (string $o) => $o !== ''));

            if (in_array($type, ['select', 'radio', 'checkbox'], true) && $options === []) {
                Http::validationFailed(
                    'A ' . $type . ' field needs at least one option.',
                    ['fields' => '"' . $label . '" has no options.'],
                );
            }

            $fields[] = [
                'label'       => substr($label, 0, 300),
                'help_text'   => substr(trim((string) ($field['help_text'] ?? '')), 0, 500),
                'field_type'  => $type,
                'options'     => $options,
                'is_required' => (bool) ($field['is_required'] ?? false),
                'sort_order'  => (int) ($field['sort_order'] ?? $index),
            ];
        }

        Db::transaction(function () use ($creating, $formUuid, $ctx, $name, $body, $fields): void {
            $values = [
                'name'         => substr($name, 0, 200),
                'description'  => substr(trim((string) ($body['description'] ?? '')), 0, 2000),
                'is_sensitive' => (bool) ($body['is_sensitive'] ?? false),
                'is_active'    => (bool) ($body['is_active'] ?? true),
                'updated_at'   => Clock::sql(Clock::now()),
            ];

            if ($creating) {
                Db::insert('appointment_forms', $values + ['form_uuid' => $formUuid, 'cmp_id' => $ctx->cmpId], 'form_uuid');
            } else {
                Db::update('appointment_forms', $values, ['form_uuid' => $formUuid, 'cmp_id' => $ctx->cmpId]);
            }

            Db::run(
                'DELETE FROM appointment_form_fields WHERE form_uuid = :id AND cmp_id = :cmp',
                ['id' => $formUuid, 'cmp' => $ctx->cmpId],
            );

            foreach ($fields as $field) {
                Db::insert('appointment_form_fields', $field + [
                    'field_uuid' => Uuid::v4(),
                    'form_uuid'  => $formUuid,
                    'cmp_id'     => $ctx->cmpId,
                ], 'field_uuid');
            }
        });

        Audit::record(
            $ctx,
            $auth,
            $creating ? 'form.created' : 'form.updated',
            'form',
            $formUuid,
            null,
            ['name' => $name, 'fields' => count($fields)],
        );

        self::show($formUuid);
    }

    /**
     * Record a client's answers against a booking.
     *
     * Validated against the form's own fields: a required question that was
     * not answered is a 422, and an answer to a question the form does not ask
     * is dropped rather than stored. Both matter because this endpoint is
     * reachable from a public booking page.
     */
    public static function submit(string $bookingUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.clients.manage');

        $booking = Db::first(
            'SELECT b.booking_uuid, s.form_uuid
               FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
              WHERE b.booking_uuid = :id AND b.cmp_id = :cmp',
            ['id' => $bookingUuid, 'cmp' => $ctx->cmpId],
        );

        if ($booking === null) {
            Http::notFound('That appointment does not exist.');
        }
        if (empty($booking['form_uuid'])) {
            Http::validationFailed('That appointment\'s service does not have a form.');
        }

        $answers = self::validateAnswers($ctx, (string) $booking['form_uuid'], (array) (Http::body()['answers'] ?? []));

        Db::run(
            'INSERT INTO appointment_booking_metadata (booking_uuid, cmp_id, kind, payload)
             VALUES (:booking, :cmp, \'form_answers\', :payload)
             ON CONFLICT (booking_uuid, kind) DO UPDATE
                SET payload = EXCLUDED.payload, updated_at = NOW()',
            [
                'booking' => $bookingUuid,
                'cmp'     => $ctx->cmpId,
                'payload' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            ],
        );

        // The answers themselves are NOT in the audit row. An audit log is read
        // by more people than the form is, and copying medical or personal
        // answers into it would widen access to them by accident.
        Audit::record($ctx, $auth, 'form.submitted', 'booking', $bookingUuid, null, [
            'form_uuid' => $booking['form_uuid'],
            'answered'  => count($answers),
        ]);

        Http::data(['submitted' => true, 'answered' => count($answers)]);
    }

    /**
     * Answers for one booking, subject to the form's sensitivity.
     */
    public static function answers(string $bookingUuid): void
    {
        [$auth, $ctx] = self::enter('appointments.clients.view');

        $row = Db::first(
            'SELECT m.payload, f.form_uuid, f.name, f.is_sensitive
               FROM ' . BookingService::TABLE . ' b
               JOIN appointment_services s ON s.service_uuid = b.service_uuid
               JOIN appointment_forms f ON f.form_uuid = s.form_uuid
          LEFT JOIN appointment_booking_metadata m
                 ON m.booking_uuid = b.booking_uuid AND m.kind = \'form_answers\'
              WHERE b.booking_uuid = :id AND b.cmp_id = :cmp',
            ['id' => $bookingUuid, 'cmp' => $ctx->cmpId],
        );

        if ($row === null) {
            Http::notFound('That appointment has no form.');
        }

        $completed = $row['payload'] !== null;

        // Whether it is done is safe for anybody who can see the diary; the
        // content of a sensitive form is not.
        if ($row['is_sensitive'] && !Permissions::allows($ctx, $auth, 'appointments.clients.manage')) {
            Http::data([
                'form'      => ['form_uuid' => (string) $row['form_uuid'], 'name' => (string) $row['name'], 'is_sensitive' => true],
                'completed' => $completed,
                'answers'   => null,
                'withheld_reason' => 'This form is marked sensitive. Answers need the "Edit appointment preferences and intake answers" permission.',
            ]);
        }

        Http::data([
            'form'      => ['form_uuid' => (string) $row['form_uuid'], 'name' => (string) $row['name'], 'is_sensitive' => (bool) $row['is_sensitive']],
            'completed' => $completed,
            'answers'   => $completed ? Db::jsonColumn($row['payload']) : null,
            'withheld_reason' => null,
        ]);
    }

    /**
     * Check answers against the form and keep only what it asked for.
     *
     * Shared with the public booking flow, which is why it validates rather
     * than trusts: a public caller can send anything.
     *
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    public static function validateAnswers(\Aicountly\Api\Context $ctx, string $formUuid, array $submitted): array
    {
        $fields = Db::all(
            'SELECT * FROM appointment_form_fields WHERE form_uuid = :id AND cmp_id = :cmp ORDER BY sort_order',
            ['id' => $formUuid, 'cmp' => $ctx->cmpId],
        );

        $answers = [];
        $errors = [];

        foreach ($fields as $field) {
            $fieldUuid = (string) $field['field_uuid'];
            $label = (string) $field['label'];
            $type = (string) $field['field_type'];
            $raw = $submitted[$fieldUuid] ?? null;

            $empty = $raw === null || $raw === '' || (is_array($raw) && $raw === []);

            if ($empty) {
                if ($field['is_required']) {
                    $errors[$fieldUuid] = $label . ' is required.';
                }
                continue;
            }

            $options = Db::jsonColumn($field['options'] ?? null);

            $value = match ($type) {
                'checkbox' => array_values(array_filter(
                    array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', (array) $raw),
                    static fn (string $v) => $v !== '' && in_array($v, $options, true),
                )),
                'select', 'radio' => in_array((string) $raw, $options, true) ? (string) $raw : null,
                'consent'  => (bool) $raw,
                'number'   => is_numeric($raw) ? $raw + 0 : null,
                'email'    => filter_var((string) $raw, FILTER_VALIDATE_EMAIL) !== false ? (string) $raw : null,
                'date'     => Clock::parse((string) $raw) !== null ? substr((string) $raw, 0, 10) : null,
                default    => substr(trim((string) $raw), 0, 4000),
            };

            if ($value === null || ($type === 'consent' && $value === false && $field['is_required'])) {
                $errors[$fieldUuid] = $type === 'consent'
                    ? $label . ' must be agreed to.'
                    : $label . ' is not a valid ' . $type . '.';
                continue;
            }

            $answers[$fieldUuid] = ['label' => $label, 'type' => $type, 'value' => $value];
        }

        if ($errors !== []) {
            Http::validationFailed('Some answers are missing or invalid.', $errors);
        }

        return $answers;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shapeForm(array $row): array
    {
        return [
            'form_uuid'     => (string) ($row['form_uuid'] ?? ''),
            'name'          => (string) ($row['name'] ?? ''),
            'description'   => (string) ($row['description'] ?? ''),
            'is_sensitive'  => (bool) ($row['is_sensitive'] ?? false),
            'is_active'     => (bool) ($row['is_active'] ?? true),
            'field_count'   => isset($row['field_count']) ? (int) $row['field_count'] : null,
            'service_count' => isset($row['service_count']) ? (int) $row['service_count'] : null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shapeField(array $row): array
    {
        return [
            'field_uuid'  => (string) ($row['field_uuid'] ?? ''),
            'label'       => (string) ($row['label'] ?? ''),
            'help_text'   => (string) ($row['help_text'] ?? ''),
            'field_type'  => (string) ($row['field_type'] ?? 'text'),
            'options'     => Db::jsonColumn($row['options'] ?? null),
            'is_required' => (bool) ($row['is_required'] ?? false),
            'sort_order'  => (int) ($row['sort_order'] ?? 0),
        ];
    }
}
