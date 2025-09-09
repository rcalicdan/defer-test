<?php

use Library\Defer\Defer;

require 'vendor/autoload.php';

// Start benchmarking
$start_time = microtime(true);

// Configuration
define('USERS_FILE', 'users.json');
define('FOOD_FILE', 'food.json');

// Pure function to initialize data file
function initializeDataFile($filename)
{
    if (!file_exists($filename)) {
        file_put_contents($filename, json_encode([]));
    }
}

// Pure function to read data from file
function readDataFromFile($filename)
{
    sleep(1);
    $data = file_get_contents($filename);
    return json_decode($data, true) ?: [];
}

// Pure function to write data to file
function writeDataToFile($filename, $data)
{
    return file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

// Pure function to get all data from both sources
// The FULLY PARALLEL version of getAllData
function getAllData()
{
    $userContext = readDataFromFile(USERS_FILE);
    $foodContext = readDataFromFile(FOOD_FILE);

    $userTask = Defer::background(function ($context) {
        return $context['usercontext'];
    }, context: [
        'usercontext' => $userContext,
    ]);

    $foodTask = Defer::background(function ($context) {
        return $context['foodcontext'];
    }, context: [
        'foodcontext' => $foodContext,
    ]);

    $totalTask = Defer::background(function ($context) {
        return array_merge($context['usercontext'], $context['foodcontext']);
    }, context: [
        'usercontext' => $userContext,
        'foodcontext' => $foodContext,
    ]);

    $total = Defer::awaitTask($totalTask);
    $users = Defer::awaitTask($userTask);
    $foods = Defer::awaitTask($foodTask);


    return [
        'users' => $users,
        'foods' => $foods,
        'stats' => [
            'user_count' => count($users),
            'food_count' => count($foods),
            'total_records' => count($total)
        ]
    ];
}

// Pure function to generate unique ID
function generateUniqueId()
{
    return uniqid();
}

// Pure function to get current timestamp
function getCurrentTimestamp()
{
    return date('Y-m-d H:i:s');
}

// Pure function to create a new record
function createRecord($data, $newRecord)
{
    $record = array_merge($newRecord, [
        'id' => generateUniqueId(),
        'created_at' => getCurrentTimestamp()
    ]);
    return array_merge($data, [$record]);
}

// Pure function to update a record
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

// Pure function to delete a record
function deleteRecord($data, $id)
{
    return array_values(array_filter($data, function ($record) use ($id) {
        return $record['id'] !== $id;
    }));
}

// Pure function to process form data
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

// Pure function to get filename by type
function getFilename($type)
{
    return $type === 'food' ? FOOD_FILE : USERS_FILE;
}

// Pure function to handle CRUD operations
function handleCrudOperation($post)
{
    if (empty($post)) {
        return ['success' => false, 'message' => ''];
    }

    $form = processFormData($post);
    $filename = getFilename($form['type']);
    $data = readDataFromFile($filename);

    switch ($form['action']) {
        case 'create':
            if ($form['type'] === 'user' && !empty($form['name']) && !empty($form['email'])) {
                $newRecord = ['name' => $form['name'], 'email' => $form['email']];
                $updatedData = createRecord($data, $newRecord);
                return writeDataToFile($filename, $updatedData) ?
                    ['success' => true, 'message' => 'User created successfully!'] :
                    ['success' => false, 'message' => 'Failed to create user.'];
            }

            if ($form['type'] === 'food' && !empty($form['food_name']) && !empty($form['category']) && !empty($form['price'])) {
                $newRecord = ['name' => $form['food_name'], 'category' => $form['category'], 'price' => $form['price']];
                $updatedData = createRecord($data, $newRecord);
                return writeDataToFile($filename, $updatedData) ?
                    ['success' => true, 'message' => 'Food item created successfully!'] :
                    ['success' => false, 'message' => 'Failed to create food item.'];
            }
            break;

        case 'update':
            $updates = [];
            if ($form['type'] === 'user') {
                if (!empty($form['name'])) $updates['name'] = $form['name'];
                if (!empty($form['email'])) $updates['email'] = $form['email'];
                $itemType = 'User';
            } else {
                if (!empty($form['food_name'])) $updates['name'] = $form['food_name'];
                if (!empty($form['category'])) $updates['category'] = $form['category'];
                if (!empty($form['price'])) $updates['price'] = $form['price'];
                $itemType = 'Food item';
            }

            if (!empty($updates) && !empty($form['id'])) {
                $updatedData = updateRecord($data, $form['id'], $updates);
                return writeDataToFile($filename, $updatedData) ?
                    ['success' => true, 'message' => "$itemType updated successfully!"] :
                    ['success' => false, 'message' => "Failed to update $itemType."];
            }
            break;

        case 'delete':
            if (!empty($form['id'])) {
                $updatedData = deleteRecord($data, $form['id']);
                $itemType = ($form['type'] === 'food') ? 'Food item' : 'User';
                return writeDataToFile($filename, $updatedData) ?
                    ['success' => true, 'message' => "$itemType deleted successfully!"] :
                    ['success' => false, 'message' => "Failed to delete $itemType."];
            }
            break;
    }

    return ['success' => false, 'message' => 'Invalid operation.'];
}

// Pure function to render user table
function renderUserTable($users)
{
    if (empty($users)) {
        return '<p>No users found.</p>';
    }

    $html = '<table border="1">
        <tr><th>ID</th><th>Name</th><th>Email</th><th>Created</th><th>Updated</th><th>Actions</th></tr>';

    foreach ($users as $user) {
        $id = htmlspecialchars($user['id']);
        $name = htmlspecialchars($user['name']);
        $email = htmlspecialchars($user['email']);
        $created = htmlspecialchars($user['created_at'] ?? 'N/A');
        $updated = htmlspecialchars($user['updated_at'] ?? 'N/A');

        $html .= "<tr>
            <td>$id</td>
            <td>$name</td>
            <td>$email</td>
            <td>$created</td>
            <td>$updated</td>
            <td>
                <form method='POST' style='display: inline;'>
                    <input type='hidden' name='action' value='update'>
                    <input type='hidden' name='type' value='user'>
                    <input type='hidden' name='id' value='$id'>
                    <input type='text' name='name' placeholder='New name'>
                    <input type='email' name='email' placeholder='New email'>
                    <button type='submit'>Update</button>
                </form>
                <form method='POST' style='display: inline;'>
                    <input type='hidden' name='action' value='delete'>
                    <input type='hidden' name='type' value='user'>
                    <input type='hidden' name='id' value='$id'>
                    <button type='submit' onclick='return confirm(\"Delete this user?\")'>Delete</button>
                </form>
            </td>
        </tr>";
    }

    return $html . '</table>';
}

// Pure function to render food table
function renderFoodTable($foods)
{
    if (empty($foods)) {
        return '<p>No food items found.</p>';
    }

    $html = '<table border="1">
        <tr><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Created</th><th>Updated</th><th>Actions</th></tr>';

    foreach ($foods as $food) {
        $id = htmlspecialchars($food['id']);
        $name = htmlspecialchars($food['name']);
        $category = htmlspecialchars($food['category']);
        $price = htmlspecialchars($food['price']);
        $created = htmlspecialchars($food['created_at'] ?? 'N/A');
        $updated = htmlspecialchars($food['updated_at'] ?? 'N/A');

        $html .= "<tr>
            <td>$id</td>
            <td>$name</td>
            <td>$category</td>
            <td>\$$price</td>
            <td>$created</td>
            <td>$updated</td>
            <td>
                <form method='POST' style='display: inline;'>
                    <input type='hidden' name='action' value='update'>
                    <input type='hidden' name='type' value='food'>
                    <input type='hidden' name='id' value='$id'>
                    <input type='text' name='food_name' placeholder='New name'>
                    <input type='text' name='category' placeholder='New category'>
                    <input type='number' name='price' step='0.01' placeholder='New price'>
                    <button type='submit'>Update</button>
                </form>
                <form method='POST' style='display: inline;'>
                    <input type='hidden' name='action' value='delete'>
                    <input type='hidden' name='type' value='food'>
                    <input type='hidden' name='id' value='$id'>
                    <button type='submit' onclick='return confirm(\"Delete this food item?\")'>Delete</button>
                </form>
            </td>
        </tr>";
    }

    return $html . '</table>';
}

// Initialize data files
initializeDataFile(USERS_FILE);
initializeDataFile(FOOD_FILE);

// Handle CRUD operations
$result = handleCrudOperation($_POST);
$message = $result['message'];

// Get all data in one function call
$allData = getAllData();

// Calculate performance metrics
$execution_time = (microtime(true) - $start_time) * 1000;
$memory_usage = number_format(memory_get_peak_usage(true) / 1024 / 1024, 2);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simplified Functional PHP CRUD</title>
</head>

<body>
    <h1>Simplified Functional PHP CRUD System</h1>
    <p><strong>Page Load Time: <?php echo number_format($execution_time, 2); ?> ms</strong></p>

    <?php if ($message): ?>
        <p><strong><?php echo htmlspecialchars($message); ?></strong></p>
    <?php endif; ?>

    <hr>

    <!-- USERS SECTION -->
    <h2>Users Management</h2>

    <h3>Add New User</h3>
    <form method="POST">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="type" value="user">
        <p>
            Name: <input type="text" name="name" required>
            Email: <input type="email" name="email" required>
            <button type="submit">Add User</button>
        </p>
    </form>

    <h3>All Users</h3>
    <?php echo renderUserTable($allData['users']); ?>

    <hr>

    <!-- FOOD SECTION -->
    <h2>Food Management</h2>

    <h3>Add New Food Item</h3>
    <form method="POST">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="type" value="food">
        <p>
            Food Name: <input type="text" name="food_name" required>
            Category: <input type="text" name="category" required>
            Price: <input type="number" name="price" step="0.01" required>
            <button type="submit">Add Food</button>
        </p>
    </form>

    <h3>All Food Items</h3>
    <?php echo renderFoodTable($allData['foods']); ?>

    <hr>
</body>

</html>