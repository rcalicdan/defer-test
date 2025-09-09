<?php 

namespace Parralel;


$start_time = microtime(true);

// Configuration
define('USERS_FILE', 'users.json');
define('FOOD_FILE', 'food.json');

function initializeDataFile($filename)
{
    if (!file_exists($filename)) {
        file_put_contents($filename, json_encode([]));
    }
    return $filename;
}


function readDataFromFile($filename)
{
    sleep(1);
    $data = file_get_contents($filename);
    return json_decode($data, true) ?: [];
}

function writeDataToFile($filename, $data)
{
    return file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

function generateUniqueId()
{
    return uniqid();
}

function getCurrentTimestamp()
{
    return date('Y-m-d H:i:s');
}


function createRecord($data, $newRecord)
{
    $record = array_merge($newRecord, [
        'id' => generateUniqueId(),
        'created_at' => getCurrentTimestamp()
    ]);
    return array_merge($data, [$record]);
}


function findRecordById($data, $id)
{
    return array_filter($data, function ($record) use ($id) {
        return $record['id'] === $id;
    });
}


function updateRecord($data, $id, $updates)
{
    return array_map(function ($record) use ($id, $updates) {
        if ($record['id'] === $id) {
            return array_merge($record, $updates, [
                'updated_at' => getCurrentTimestamp()
            ]);
        }
        return $record;
    }, $data);
}


function deleteRecord($data, $id)
{
    return array_values(array_filter($data, function ($record) use ($id) {
        return $record['id'] !== $id;
    }));
}


function validateUserData($name, $email)
{
    return !empty($name) && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
}


function validateFoodData($name, $category, $price)
{
    return !empty($name) && !empty($category) && is_numeric($price) && $price > 0;
}


function createUserRecord($name, $email)
{
    return ['name' => $name, 'email' => $email];
}


function createFoodRecord($name, $category, $price)
{
    return ['name' => $name, 'category' => $category, 'price' => $price];
}


function processFormData($post)
{
    return [
        'action' => $post['action'] ?? '',
        'type' => $post['type'] ?? '',
        'id' => $post['id'] ?? '',
        'name' => trim($post['name'] ?? ''),
        'email' => trim($post['email'] ?? ''),
        'food_name' => trim($post['food_name'] ?? ''),
        'category' => trim($post['category'] ?? ''),
        'price' => $post['price'] ?? ''
    ];
}


function handleCreateOperation($formData, $filename)
{
    $data = readDataFromFile($filename);

    if ($formData['type'] === 'user' && validateUserData($formData['name'], $formData['email'])) {
        $newRecord = createUserRecord($formData['name'], $formData['email']);
        $updatedData = createRecord($data, $newRecord);
        return writeDataToFile($filename, $updatedData) ?
            ['success' => true, 'message' => 'User created successfully!'] :
            ['success' => false, 'message' => 'Failed to create user.'];
    }

    if ($formData['type'] === 'food' && validateFoodData($formData['food_name'], $formData['category'], $formData['price'])) {
        $newRecord = createFoodRecord($formData['food_name'], $formData['category'], $formData['price']);
        $updatedData = createRecord($data, $newRecord);
        return writeDataToFile($filename, $updatedData) ?
            ['success' => true, 'message' => 'Food item created successfully!'] :
            ['success' => false, 'message' => 'Failed to create food item.'];
    }

    return ['success' => false, 'message' => 'Invalid data provided.'];
}

// Pure function to handle update operation
function handleUpdateOperation($formData, $filename)
{
    $data = readDataFromFile($filename);
    $updates = [];

    if ($formData['type'] === 'user') {
        if (!empty($formData['name'])) $updates['name'] = $formData['name'];
        if (!empty($formData['email'])) $updates['email'] = $formData['email'];
        $itemType = 'User';
    } elseif ($formData['type'] === 'food') {
        if (!empty($formData['food_name'])) $updates['name'] = $formData['food_name'];
        if (!empty($formData['category'])) $updates['category'] = $formData['category'];
        if (!empty($formData['price'])) $updates['price'] = $formData['price'];
        $itemType = 'Food item';
    }

    if (!empty($updates) && !empty($formData['id'])) {
        $updatedData = updateRecord($data, $formData['id'], $updates);
        return writeDataToFile($filename, $updatedData) ?
            ['success' => true, 'message' => "$itemType updated successfully!"] :
            ['success' => false, 'message' => "Failed to update $itemType."];
    }

    return ['success' => false, 'message' => 'No valid updates provided.'];
}

// Pure function to handle delete operation
function handleDeleteOperation($formData, $filename)
{
    if (empty($formData['id'])) {
        return ['success' => false, 'message' => 'No ID provided for deletion.'];
    }

    $data = readDataFromFile($filename);
    $updatedData = deleteRecord($data, $formData['id']);
    $itemType = ($formData['type'] === 'food') ? 'Food item' : 'User';

    return writeDataToFile($filename, $updatedData) ?
        ['success' => true, 'message' => "$itemType deleted successfully!"] :
        ['success' => false, 'message' => "Failed to delete $itemType."];
}

// Pure function to get appropriate filename
function getFilename($type)
{
    return $type === 'food' ? FOOD_FILE : USERS_FILE;
}

// Pure function to process request
function processRequest($post)
{
    if (empty($post)) {
        return ['success' => false, 'message' => ''];
    }

    $formData = processFormData($post);
    $filename = getFilename($formData['type']);

    switch ($formData['action']) {
        case 'create':
            return handleCreateOperation($formData, $filename);
        case 'update':
            return handleUpdateOperation($formData, $filename);
        case 'delete':
            return handleDeleteOperation($formData, $filename);
        default:
            return ['success' => false, 'message' => 'Invalid action.'];
    }
}

// Pure function to calculate execution time
function calculateExecutionTime($start_time)
{
    return (microtime(true) - $start_time) * 1000;
}

// Pure function to get memory usage
function getMemoryUsage()
{
    return number_format(memory_get_peak_usage(true) / 1024 / 1024, 2);
}

// Pure function to render table row
function renderTableRow($record, $type)
{
    $id = htmlspecialchars($record['id']);
    $created = htmlspecialchars($record['created_at'] ?? 'N/A');
    $updated = htmlspecialchars($record['updated_at'] ?? 'N/A');

    if ($type === 'user') {
        $name = htmlspecialchars($record['name']);
        $email = htmlspecialchars($record['email']);
        $dataRow = "<td>$id</td><td>$name</td><td>$email</td><td>$created</td><td>$updated</td>";
        $updateForm = '<form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="type" value="user">
            <input type="hidden" name="id" value="' . $id . '">
            <input type="text" name="name" placeholder="New name">
            <input type="email" name="email" placeholder="New email">
            <button type="submit">Update</button>
        </form>';
    } else {
        $name = htmlspecialchars($record['name']);
        $category = htmlspecialchars($record['category']);
        $price = htmlspecialchars($record['price']);
        $dataRow = "<td>$id</td><td>$name</td><td>$category</td><td>\$$price</td><td>$created</td><td>$updated</td>";
        $updateForm = '<form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="type" value="food">
            <input type="hidden" name="id" value="' . $id . '">
            <input type="text" name="food_name" placeholder="New name">
            <input type="text" name="category" placeholder="New category">
            <input type="number" name="price" step="0.01" placeholder="New price">
            <button type="submit">Update</button>
        </form>';
    }

    $deleteForm = '<form method="POST" style="display: inline;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="type" value="' . $type . '">
        <input type="hidden" name="id" value="' . $id . '">
        <button type="submit" onclick="return confirm(\'Delete this ' . $type . '?\')">Delete</button>
    </form>';

    return "<tr>$dataRow<td>$updateForm $deleteForm</td></tr>";
}

// Pure function to render table
function renderTable($records, $type, $headers)
{
    if (empty($records)) {
        $itemType = $type === 'user' ? 'users' : 'food items';
        return "<p>No $itemType found.</p>";
    }

    $headerRow = '<tr>' . implode('', array_map(function ($header) {
        return "<th>$header</th>";
    }, $headers)) . '</tr>';

    $rows = array_map(function ($record) use ($type) {
        return renderTableRow($record, $type);
    }, $records);

    return '<table border="1">' . $headerRow . implode('', $rows) . '</table>';
}
