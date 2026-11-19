<?php
declare(strict_types=1);

/**
 * SHED - Wellness API Controller
 * Phase 4.2: Duplicate-safe record creation.
 */

/**
 * Convert a database row to the Ionic model.
 */
function wellnessToApi(array $row): array
{
    $symptoms = json_decode(
        (string) $row['symptoms'],
        true
    );

    return [
        'id' => (int) $row['id'],
        'clientRecordId' => $row['client_record_id'],
        'date' => $row['entry_date'],
        'mood' => $row['mood'],
        'symptoms' => is_array($symptoms)
            ? $symptoms
            : [],
        'notes' => $row['notes']
    ];
}

/**
 * Validate optional client-generated UUID.
 */
function validateWellnessClientId(array $input): ?string
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
 * Find record by user and client record ID.
 */
function findWellnessByClientId(
    PDO $pdo,
    int $userId,
    string $clientId
): array|false {

    $statement = $pdo->prepare(
        'SELECT id, client_record_id,
                entry_date, mood, symptoms, notes
         FROM wellness_records
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
 * Compare the existing stored record with
 * the payload of a repeated request.
 *
 * Symptom order is not significant.
 * Repeated identical symptoms remain significant.
 */
function sameWellnessContent(
    array $existing,
    array $data
): bool {

    $savedSymptoms = json_decode(
        (string) $existing['symptoms'],
        true
    );

    if (!is_array($savedSymptoms)) {
        return false;
    }

    $newSymptoms = $data['symptoms'];

    sort($savedSymptoms, SORT_STRING);
    sort($newSymptoms, SORT_STRING);

    return (
        $existing['entry_date'] === $data['date'] &&
        $existing['mood'] === $data['mood'] &&
        $savedSymptoms === $newSymptoms &&
        $existing['notes'] === $data['notes']
    );
}

/**
 * Handle repeated client record IDs.
 */
function handleExistingWellnessClientId(
    array $existing,
    array $data
): never {

    if (sameWellnessContent($existing, $data)) {
        respond(200, [
            'success' => true,
            'message' => 'Wellness record already saved.',
            'data' => wellnessToApi($existing)
        ]);
    }

    respond(409, [
        'success' => false,
        'message' =>
            'This client record ID already exists ' .
            'with different wellness details. ' .
            'No record was changed.'
    ]);
}

/**
 * Authenticated Wellness CRUD.
 */
function handleWellnessRequest(
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
                    entry_date, mood, symptoms, notes
             FROM wellness_records
             WHERE user_id = ?
             ORDER BY entry_date DESC, id DESC'
        );

        $statement->execute([$userId]);

        $records = array_map(
            'wellnessToApi',
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
                    entry_date, mood, symptoms, notes
             FROM wellness_records
             WHERE id = ? AND user_id = ?
             LIMIT 1'
        );

        $statement->execute([$id, $userId]);

        $record = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            respond(404, [
                'success' => false,
                'message' => 'Wellness record not found.'
            ]);
        }

        respond(200, [
            'success' => true,
            'data' => wellnessToApi($record)
        ]);
    }

    // ========================================
    // CREATE (DUPLICATE-SAFE)
    // ========================================

    if ($method === 'POST' && $id === null) {

        $input = readJson();
        $data = validateWellnessPayload($input);
        $clientId = validateWellnessClientId($input);

        // Check whether it was already created.
        if ($clientId !== null) {

            $existing = findWellnessByClientId(
                $pdo,
                $userId,
                $clientId
            );

            if ($existing) {
                handleExistingWellnessClientId(
                    $existing,
                    $data
                );
            }
        }

        $statement = $pdo->prepare(
            'INSERT INTO wellness_records
                (user_id, client_record_id,
                 entry_date, mood, symptoms, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        try {
            $statement->execute([
                $userId,
                $clientId,
                $data['date'],
                $data['mood'],
                json_encode(
                    $data['symptoms'],
                    JSON_THROW_ON_ERROR
                ),
                $data['notes']
            ]);
        } catch (PDOException $exception) {

            // Catch a concurrent duplicate POST.
            $mysqlCode = (int) (
                $exception->errorInfo[1] ?? 0
            );

            if (
                $clientId !== null &&
                $mysqlCode === 1062
            ) {

                $existing = findWellnessByClientId(
                    $pdo,
                    $userId,
                    $clientId
                );

                if ($existing) {
                    handleExistingWellnessClientId(
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
            'message' => 'Wellness record created.',
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
        $data = validateWellnessPayload($input);

        $check = $pdo->prepare(
            'SELECT id, client_record_id
             FROM wellness_records
             WHERE id = ? AND user_id = ?
             LIMIT 1'
        );

        $check->execute([$id, $userId]);

        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            respond(404, [
                'success' => false,
                'message' => 'Wellness record not found.'
            ]);
        }

        // Sync ID must remain unchanged.
        if (array_key_exists('clientRecordId', $input)) {

            $submittedId = validateWellnessClientId(
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
            'UPDATE wellness_records
             SET entry_date = ?,
                 mood = ?,
                 symptoms = ?,
                 notes = ?
             WHERE id = ? AND user_id = ?'
        );

        $statement->execute([
            $data['date'],
            $data['mood'],
            json_encode(
                $data['symptoms'],
                JSON_THROW_ON_ERROR
            ),
            $data['notes'],
            $id,
            $userId
        ]);

        respond(200, [
            'success' => true,
            'message' => 'Wellness record updated.',
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
            'DELETE FROM wellness_records
             WHERE id = ? AND user_id = ?'
        );

        $statement->execute([$id, $userId]);

        if ($statement->rowCount() === 0) {
            respond(404, [
                'success' => false,
                'message' => 'Wellness record not found.'
            ]);
        }

        respond(200, [
            'success' => true,
            'message' => 'Wellness record deleted.'
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