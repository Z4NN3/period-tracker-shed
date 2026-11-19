<?php
declare(strict_types=1);

/**
 * Validate a calendar date using YYYY-MM-DD.
 */
function isValidCalendarDate(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value,
        new DateTimeZone('Asia/Manila')
    );

    return $date !== false
        && $date->format('Y-m-d') === $value;
}

/**
 * Check that a date is not in the future.
 */
function isNotFutureDate(string $date): bool
{
    $today = new DateTimeImmutable(
        'today',
        new DateTimeZone('Asia/Manila')
    );

    return $date <= $today->format('Y-m-d');
}

/**
 * Validate the complete PeriodRecord input.
 */
function validatePeriodPayload(array $data): array
{
    $startDate = $data['startDate'] ?? null;
    $endDate = $data['endDate'] ?? null;
    $flow = $data['flow'] ?? null;
    $notes = $data['notes'] ?? null;

    if (
        !isValidCalendarDate($startDate) ||
        !isValidCalendarDate($endDate)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Please enter valid period dates.'
        ]);
    }

    if ($endDate < $startDate) {
        respond(422, [
            'success' => false,
            'message' => 'End date cannot be before start date.'
        ]);
    }

    if (
        !isNotFutureDate($startDate) ||
        !isNotFutureDate($endDate)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Future period dates cannot be logged.'
        ]);
    }

    if (
        !is_string($flow) ||
        !in_array($flow, ['Light', 'Medium', 'Heavy'], true)
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid period flow.'
        ]);
    }

    if (
        !is_string($notes) ||
        mb_strlen($notes) > 2000
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid notes. Maximum 2000 characters.'
        ]);
    }

    return [
        'startDate' => $startDate,
        'endDate' => $endDate,
        'flow' => $flow,
        'notes' => trim($notes)
    ];
}

/**
 * Validate the complete WellnessRecord input.
 */
function validateWellnessPayload(array $data): array
{
    $date = $data['date'] ?? null;
    $mood = $data['mood'] ?? null;
    $symptoms = $data['symptoms'] ?? null;
    $notes = $data['notes'] ?? null;

    if (!isValidCalendarDate($date)) {
        respond(422, [
            'success' => false,
            'message' => 'Please select a valid wellness date.'
        ]);
    }

    if (!isNotFutureDate($date)) {
        respond(422, [
            'success' => false,
            'message' => 'Future wellness entries cannot be logged.'
        ]);
    }

    if (
        !is_string($mood) ||
        mb_strlen($mood) > 50
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid mood.'
        ]);
    }

    if (
        !is_array($symptoms) ||
        !array_is_list($symptoms) ||
        count($symptoms) > 30
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Symptoms must be a list of up to 30 items.'
        ]);
    }

    foreach ($symptoms as $symptom) {
        if (
            !is_string($symptom) ||
            mb_strlen($symptom) > 100
        ) {
            respond(422, [
                'success' => false,
                'message' => 'Each symptom must be a string of up to 100 characters.'
            ]);
        }
    }

    if (
        !is_string($notes) ||
        mb_strlen($notes) > 2000
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid notes. Maximum 2000 characters.'
        ]);
    }

    return [
        'date' => $date,
        'mood' => trim($mood),
        'symptoms' => $symptoms,
        'notes' => trim($notes)
    ];
}