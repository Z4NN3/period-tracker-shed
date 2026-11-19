<?php
declare(strict_types=1);

/**
 * SHED - Period API Controller
 * Phase 4.2: Duplicate-safe record creation.
 *
 * clientRecordId is optional for compatibility
 * with existing API clients.
 *
 * It is immutable after record creation.
 */

/**
 * Convert a database row to the Ionic model.
 */
function periodToApi(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'clientRecordId' => $row['client_record_id'],
        'startDate' => $row['start_date'],
        'endDate' => $row['end_date'],
        'flow' => $row['flow'],
        'notes' => $row['notes']
    ];
}

/**
 * Validate an optional client-generated UUID.
 *
 * null means an older API client created the
 * record without a sync identifier.
 */
function validatePeriodClientId(array $input): ?string
{
    if (!array_key_exists('clientRecordId', $input)) {
        return null;
    }

    $clientId = $input['clientRecordId'];

    if (
        !is_string($clientId) ||
        !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $clientId
        )
    ) {
        respond(422, [
            'success' => false,
            'message' => 'Invalid client record ID.'
        ]);
    }

    return strtolower($clientId);
}

/**
 * Find a record by authenticated user and
 * immutable client record ID.
 */
function findPeriodByClientId(
    PDO $pdo,
    int $userId,
    string $clientId
): array|false {
    $statement = $pdo->prepare(
        'SELECT id, client_record_id,
                start_date, end_date, flow, notes
         FROM period_records
         WHERE user_id = ?
           AND client_record_id = ?
         LIMIT 1'
    );

    $statement->execute([
        $userId,
        $clientId
    ]);

    return $statement->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check whether a retry has the same content
 * as the already-saved record.
 */
function samePeriodContent(
    array $existing,
    array $data
): bool {
    return (
        $existing['start_date'] === $data['startDate'] &&
        $existing['end_date'] === $data['endDate'] &&
        $existing['flow'] === $data['flow'] &&
        $existing['notes'] === $data['notes']
    );
}

/**
 * Respond to an already-used client record ID.
 *
 * Same content: successful idempotent retry.
 * Different content: conflict, no overwrite.
 */
function handleExistingPeriodClientId(
    array $existing,
    array $data
): never {
    if (samePeriodContent($existing, $data)) {
        respond(200, [
            'success' => true,
            'message' => 'Period record already saved.',
            'data' => periodToApi($existing)
        ]);
    }

    respond(409, [
        'success' => false,
        'message' =>
            'This client record ID already exists ' .
            'with different period details. ' .
            'No record was changed.'
    ]);
}

/**
 * Authenticated Period CRUD.
 */
function handlePeriodRequest(
    PDO $pdo,
    string $method,
    ?int $id,
    int $userId
): never {

    // ========================================
    // GET ALL
    // ========================================

    if ($method === 'GET' && $id === null) {
        $statement = $pdo->prepare(
            'SELECT id, client_record_id,
                    start_date, end_date, flow, notes
             FROM period_records
             WHERE user_id = ?
             ORDER BY start_date DESC, id DESC'
        );

        $statement->execute([$userId]);

        $records = array_map(
            'periodToApi',
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );

        respond(200, [
            'success' => true,
            'data' => $records
        ]);
    }

    // ========================================
    // GET ONE
    // ========================================

    if ($method === 'GET' && $id !== null) {
        $statement = $pdo->prepare(
            'SELECT id, client_record_id,
                    start_date, end_date, flow, notes
             FROM period_records
             WHERE id = ? AND user_id = ?
             LIMIT 1'
        );

        $statement->execute([$id, $userId]);

        $record = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            respond(404, [
                'success' => false,
                'message' => 'Period record not found.'
            ]);
        }

        respond(200, [
            'success' => true,
            'data' => periodToApi($record)
        ]);
    }

    // ========================================
    // CREATE (DUPLICATE-SAFE)
    // ========================================

    if ($method === 'POST' && $id === null) {
        $input = readJson();
        $data = validatePeriodPayload($input);
        $clientId = validatePeriodClientId($input);

        // Check for an existing record first.
        if ($clientId !== null) {
            $existing = findPeriodByClientId(
                $pdo,
                $userId,
                $clientId
            );

            if ($existing) {
                handleExistingPeriodClientId(
                    $existing,
                    $data
                );
            }
        }

        $statement = $pdo->prepare(
            'INSERT INTO period_records
                (user_id, client_record_id,
                 start_date, end_date, flow, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        try {
            $statement->execute([
                $userId,
                $clientId,
                $data['startDate'],
                $data['endDate'],
                $data['flow'],
                $data['notes']
            ]);
        } catch (PDOException $exception) {
            // Handle simultaneous repeated requests.
            // Only MySQL duplicate-key errors qualify.
            $mysqlCode = (int) (
                $exception->errorInfo[1] ?? 0
            );

            if ($clientId !== null && $mysqlCode === 1062) {
                $existing = findPeriodByClientId(
                    $pdo,
                    $userId,
                    $clientId
                );

                if ($existing) {
                    handleExistingPeriodClientId(
                        $existing,
                        $data
                    );
                }
            }

            throw $exception;
        }

        $newId = (int) $pdo->lastInsertId();

        respond(201, [
            'success' => true,
            'message' => 'Period record created.',
            'data' => [
                'id' => $newId,
                'clientRecordId' => $clientId,
                ...$data
            ]
        ]);
    }

    // ========================================
    // UPDATE
    // ========================================

    if ($method === 'PUT' && $id !== null) {
        $input = readJson();
        $data = validatePeriodPayload($input);

        $check = $pdo->prepare(
            'SELECT id, client_record_id
             FROM period_records
             WHERE id = ? AND user_id = ?
             LIMIT 1'
        );

        $check->execute([$id, $userId]);

        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            respond(404, [
                'success' => false,
                'message' => 'Period record not found.'
            ]);
        }

        // Prevent reassigning the immutable sync ID.
        if (array_key_exists('clientRecordId', $input)) {
            $submittedId = validatePeriodClientId(
                $input
            );

            if (
                $submittedId !==
                $existing['client_record_id']
            ) {
                respond(409, [
                    'success' => false,
                    'message' =>
                        'Client record ID cannot be changed.'
                ]);
            }
        }

        $statement = $pdo->prepare(
            'UPDATE period_records
             SET start_date = ?,
                 end_date = ?,
                 flow = ?,
                 notes = ?
             WHERE id = ? AND user_id = ?'
        );

        $statement->execute([
            $data['startDate'],
            $data['endDate'],
            $data['flow'],
            $data['notes'],
            $id,
            $userId
        ]);

        respond(200, [
            'success' => true,
            'message' => 'Period record updated.',
            'data' => [
                'id' => $id,
                'clientRecordId' =>
                    $existing['client_record_id'],
                ...$data
            ]
        ]);
    }

    // ========================================
    // DELETE
    // ========================================

    if ($method === 'DELETE' && $id !== null) {
        $statement = $pdo->prepare(
            'DELETE FROM period_records
             WHERE id = ? AND user_id = ?'
        );

        $statement->execute([$id, $userId]);

        if ($statement->rowCount() === 0) {
            respond(404, [
                'success' => false,
                'message' => 'Period record not found.'
            ]);
        }

        respond(200, [
            'success' => true,
            'message' => 'Period record deleted.'
        ]);
    }

    // ========================================
    // UNSUPPORTED METHOD
    // ========================================

    header(
        'Allow: ' . (
            $id === null
                ? 'GET, POST'
                : 'GET, PUT, DELETE'
        )
    );

    respond(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}