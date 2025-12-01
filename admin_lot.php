<?php
// Include database configuration
include_once 'db_config.php';

// --- DATABASE FUNCTIONS ---

/**
 * Executes a simple query and returns the result set.
 * @param string $sql The SQL query to execute.
 * @return mysqli_result|bool The result set or false on failure.
 */
function db_query($sql) {
    global $conn;
    return $conn->query($sql);
}

// --- CATEGORY MANAGEMENT ---

if (isset($_POST['category_action'])) {
    $action = $_POST['category_action'];
    $description = $conn->real_escape_string($_POST['category_description']);
    $categoryId = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;

    if ($action == 'add') {
        $sql = "INSERT INTO Category (Description) VALUES ('$description')";
        db_query($sql);
    } elseif ($action == 'edit' && $categoryId > 0) {
        $sql = "UPDATE Category SET Description = '$description' WHERE CategoryID = $categoryId";
        db_query($sql);
    } elseif ($action == 'delete' && $categoryId > 0) {
        // First, set Lot.CategoryID to NULL for lots using this category (if allowed by schema, or use a default category)
        $conn->query("UPDATE Lot SET CategoryID = NULL WHERE CategoryID = $categoryId");
        $sql = "DELETE FROM Category WHERE CategoryID = $categoryId";
        db_query($sql);
    }
}

// --- LOT MANAGEMENT ---

if (isset($_POST['lot_action'])) {
    $action = $_POST['lot_action'];
    $lotId = isset($_POST['lot_id']) ? intval($_POST['lot_id']) : 0;
    $description = $conn->real_escape_string($_POST['lot_description']);
    $categoryId = intval($_POST['lot_category_id']);
    // Placeholder for actual image upload path logic (simplified here)
    $photo_path = $conn->real_escape_string($_POST['lot_photo_path'] ?? ''); 

    if ($action == 'add') {
        // Assuming Lot table has a PhotoPath field
        $sql = "INSERT INTO Lot (Description, CategoryID, PhotoPath) VALUES ('$description', $categoryId, '$photo_path')";
        db_query($sql);
        $newLotId = $conn->insert_id;
        $lotId = $newLotId; // Use new ID for item assignment
    } elseif ($action == 'edit' && $lotId > 0) {
        $sql = "UPDATE Lot SET Description = '$description', CategoryID = $categoryId, PhotoPath = '$photo_path' WHERE LotID = $lotId";
        db_query($sql);
    } elseif ($action == 'delete' && $lotId > 0) {
        // Remove item assignments first
        $conn->query("UPDATE Item SET LotID = NULL WHERE LotID = $lotId");
        $sql = "DELETE FROM Lot WHERE LotID = $lotId";
        db_query($sql);
        $lotId = 0; // Reset lot ID after deletion
    }

    // --- ITEM ASSIGNMENT (Applies to both Add and Edit) ---
    if ($lotId > 0 && isset($_POST['item_ids'])) {
        $item_ids = $_POST['item_ids'];
        
        // 1. Clear current assignments for this lot (for cleanup/reassignment)
        $conn->query("UPDATE Item SET LotID = NULL WHERE LotID = $lotId");

        // 2. Assign selected items to this lot
        if (!empty($item_ids)) {
            $item_id_list = implode(',', array_map('intval', $item_ids));
            $conn->query("UPDATE Item SET LotID = $lotId WHERE ItemID IN ($item_id_list)");
        }
    }
}

// Fetch all Categories for dropdowns
$categories_result = db_query("SELECT * FROM Category ORDER BY Description");
$categories = [];
while ($row = $categories_result->fetch_assoc()) {
    $categories[] = $row;
}

// Fetch all Lots
$lots_result = db_query("SELECT L.*, C.Description AS CategoryName FROM Lot L LEFT JOIN Category C ON L.CategoryID = C.CategoryID ORDER BY L.LotID");
$lots = [];
while ($row = $lots_result->fetch_assoc()) {
    $lots[] = $row;
}

// Fetch all Unassigned Items for lot assignment UI
$unassigned_items_result = db_query("
    SELECT I.ItemID, I.Description, I.RetailValue, D.BusinessName 
    FROM Item I 
    LEFT JOIN Donor D ON I.DonorID = D.DonorID
    WHERE I.LotID IS NULL OR I.LotID = 0
    ORDER BY I.ItemID
");
$unassigned_items = [];
while ($row = $unassigned_items_result->fetch_assoc()) {
    $unassigned_items[] = $row;
}

// Function to fetch items currently assigned to a specific lot
function get_lot_items($lotId) {
    global $conn;
    $sql = "SELECT I.ItemID, I.Description, I.RetailValue, D.BusinessName 
            FROM Item I 
            LEFT JOIN Donor D ON I.DonorID = D.DonorID
            WHERE I.LotID = " . intval($lotId);
    $result = db_query($sql);
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    return $items;
}

// Function to get ALL items (assigned and unassigned) for the Lot Edit Modal
function get_all_items() {
    global $conn;
    $sql = "SELECT I.ItemID, I.Description, I.RetailValue, I.LotID, D.BusinessName 
            FROM Item I 
            LEFT JOIN Donor D ON I.DonorID = D.DonorID
            ORDER BY I.LotID, I.ItemID";
    $result = db_query($sql);
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    return $items;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auction Admin - Lot & Category Management</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .container { max-width: 1200px; }
        .tab-button.active {
            @apply border-b-4 border-indigo-500 font-bold text-indigo-700;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 90%;
            width: 800px;
        }
        .close-btn {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
        .close-btn:hover, .close-btn:focus {
            color: #000;
            text-decoration: none;
            cursor: pointer;
        }
        .item-list-container {
            max-height: 250px;
            overflow-y: scroll;
            border: 1px solid #e2e8f0;
            padding: 10px;
            border-radius: 4px;
        }
        .item-row {
            padding: 5px 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        .item-row:last-child {
            border-bottom: none;
        }
        @media print {
            body * { visibility: hidden; }
            #bidding-sheet, #bidding-sheet * { visibility: visible; }
            #bidding-sheet { 
                position: absolute; 
                left: 0; 
                top: 0; 
                width: 100%;
                padding: 20px;
            }
        }
    </style>
</head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal">

    <div class="container mx-auto p-4">
        <h1 class="text-3xl font-bold text-gray-800 mb-6 border-b-2 pb-2">Silent Auction Administration</h1>
        
        <div class="bg-white p-6 rounded-lg shadow-xl">
            <!-- Tabs -->
            <div class="flex border-b mb-6">
                <button class="tab-button p-4 text-lg" data-tab="categories">Category Management</button>
                <button class="tab-button p-4 text-lg active" data-tab="lots">Lot Management</button>
            </div>

            <!-- Categories Tab Content -->
            <div id="categories" class="tab-content" style="display:none;">
                <h2 class="text-2xl font-semibold mb-4">Manage Categories</h2>
                
                <!-- Add Category Form -->
                <form method="POST" class="flex space-x-4 mb-8 p-4 bg-indigo-50 rounded-lg">
                    <input type="hidden" name="category_action" id="category_action" value="add">
                    <input type="hidden" name="category_id" id="category_id_field" value="0">
                    <input type="text" name="category_description" id="category_description_field" placeholder="New Category Name (e.g., On the Town)" required 
                           class="flex-grow p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">
                    <button type="submit" id="category_submit_btn" class="bg-indigo-500 hover:bg-indigo-600 text-white font-bold py-2 px-4 rounded-lg transition duration-150">Add Category</button>
                    <button type="button" id="category_cancel_btn" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded-lg transition duration-150" style="display:none;">Cancel Edit</button>
                </form>

                <!-- Category List -->
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white rounded-lg shadow-md">
                        <thead class="bg-gray-200">
                            <tr>
                                <th class="py-2 px-4 text-left text-gray-600">ID</th>
                                <th class="py-2 px-4 text-left text-gray-600">Description</th>
                                <th class="py-2 px-4 text-left text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="py-2 px-4"><?php echo htmlspecialchars($cat['CategoryID']); ?></td>
                                <td class="py-2 px-4"><?php echo htmlspecialchars($cat['Description']); ?></td>
                                <td class="py-2 px-4 space-x-2">
                                    <button onclick="editCategory(<?php echo $cat['CategoryID']; ?>, '<?php echo htmlspecialchars($cat['Description'], ENT_QUOTES); ?>')" class="text-indigo-600 hover:text-indigo-800 text-sm">Edit</button>
                                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this category? All associated lots will lose their category assignment.');">
                                        <input type="hidden" name="category_action" value="delete">
                                        <input type="hidden" name="category_id" value="<?php echo $cat['CategoryID']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Lots Tab Content -->
            <div id="lots" class="tab-content">
                <h2 class="text-2xl font-semibold mb-4">Manage Auction Lots</h2>

                <button onclick="openLotModal(0)" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded-lg mb-6 transition duration-150">Add New Lot</button>
                
                <!-- Lot List -->
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white rounded-lg shadow-md">
                        <thead class="bg-gray-200">
                            <tr>
                                <th class="py-2 px-4 text-left text-gray-600">Lot #</th>
                                <th class="py-2 px-4 text-left text-gray-600">Description</th>
                                <th class="py-2 px-4 text-left text-gray-600">Category</th>
                                <th class="py-2 px-4 text-left text-gray-600">Items</th>
                                <th class="py-2 px-4 text-left text-gray-600">Photo Path</th>
                                <th class="py-2 px-4 text-left text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lots as $lot): 
                                $lot_items = get_lot_items($lot['LotID']);
                                $item_count = count($lot_items);
                            ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="py-2 px-4 font-bold"><?php echo htmlspecialchars($lot['LotID']); ?></td>
                                <td class="py-2 px-4"><?php echo htmlspecialchars($lot['Description']); ?></td>
                                <td class="py-2 px-4"><?php echo htmlspecialchars($lot['CategoryName'] ?? 'Uncategorized'); ?></td>
                                <td class="py-2 px-4 text-sm">
                                    <?php echo $item_count; ?> item(s)
                                    <?php if ($item_count > 0): ?>
                                    <div class="text-xs text-gray-500 italic">
                                        (e.g., <?php echo htmlspecialchars($lot_items[0]['Description']); ?><?php echo $item_count > 1 ? '...' : ''; ?>)
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 px-4 text-xs text-blue-500 truncate max-w-xs"><?php echo htmlspecialchars($lot['PhotoPath'] ?? 'N/A'); ?></td>
                                <td class="py-2 px-4 space-x-2">
                                    <button onclick="openLotModal(<?php echo $lot['LotID']; ?>)" class="text-indigo-600 hover:text-indigo-800 text-sm">Edit</button>
                                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete Lot #<?php echo $lot['LotID']; ?>? All assigned items will become unassigned.');">
                                        <input type="hidden" name="lot_action" value="delete">
                                        <input type="hidden" name="lot_id" value="<?php echo $lot['LotID']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm">Delete</button>
                                    </form>
                                    <button onclick="printBiddingSheet(<?php echo $lot['LotID']; ?>)" class="text-teal-600 hover:text-teal-800 text-sm">Print Sheet</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Hidden div for printing bidding sheets -->
                <div id="bidding-sheet" style="display:none; margin-top: 40px; padding: 20px; border: 1px solid #000; font-family: sans-serif; line-height: 1.6;">
                    <!-- Content will be injected by JavaScript -->
                </div>
            </div>
            
        </div>
    </div>

    <!-- Lot Edit/Add Modal -->
    <div id="lotModal" class="modal">
        <div class="modal-content">
            <span class="close-btn">&times;</span>
            <h3 class="text-2xl font-bold mb-4" id="lotModalTitle">Add New Lot</h3>
            <form method="POST" id="lotForm">
                <input type="hidden" name="lot_action" id="lot_action_field" value="add">
                <input type="hidden" name="lot_id" id="lot_id_field_modal" value="0">

                <div class="mb-4">
                    <label for="lot_description" class="block text-gray-700 font-semibold mb-2">Lot Description</label>
                    <input type="text" name="lot_description" id="lot_description" required 
                           class="w-full p-2 border border-gray-300 rounded-lg">
                </div>
                
                <div class="mb-4">
                    <label for="lot_category_id" class="block text-gray-700 font-semibold mb-2">Assign Category</label>
                    <select name="lot_category_id" id="lot_category_id" required 
                            class="w-full p-2 border border-gray-300 rounded-lg">
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['CategoryID']; ?>"><?php echo htmlspecialchars($cat['Description']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label for="lot_photo_path" class="block text-gray-700 font-semibold mb-2">Photo Path / URL</label>
                    <input type="text" name="lot_photo_path" id="lot_photo_path" placeholder="e.g., /images/lot_123.jpg"
                           class="w-full p-2 border border-gray-300 rounded-lg">
                </div>

                <div class="mb-6">
                    <label class="block text-gray-700 font-semibold mb-2">Assign Items to Lot (Multi-select)</label>
                    <div class="item-list-container">
                        <?php 
                        $all_items = get_all_items(); 
                        if (empty($all_items)):
                        ?>
                        <p class="text-sm text-red-500">No items available to assign. Please add items (Part 1 first).</p>
                        <?php endif; ?>

                        <?php foreach ($all_items as $item): ?>
                        <div class="item-row flex items-center justify-between">
                            <label class="flex items-center space-x-2 cursor-pointer">
                                <input type="checkbox" name="item_ids[]" value="<?php echo $item['ItemID']; ?>" data-lot-id="<?php echo $item['LotID']; ?>" class="item-checkbox rounded text-indigo-500 focus:ring-indigo-500">
                                <span class="text-sm text-gray-800 font-medium">#<?php echo $item['ItemID']; ?>: <?php echo htmlspecialchars($item['Description']); ?></span>
                            </label>
                            <span class="text-xs text-gray-500">
                                (Value: $<?php echo number_format($item['RetailValue'], 2); ?>)
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Check the boxes for all items that belong in this lot. 
                        Items currently assigned to other lots will be automatically moved here.
                    </p>
                </div>

                <div class="flex justify-end space-x-4">
                    <button type="button" onclick="document.getElementById('lotModal').style.display='none'" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded-lg transition duration-150">Cancel</button>
                    <button type="submit" class="bg-indigo-500 hover:bg-indigo-600 text-white font-bold py-2 px-4 rounded-lg transition duration-150">Save Lot</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- Tab Logic ---
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', () => {
                // Remove active class from all buttons and hide all content
                document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.style.display = 'none');

                // Add active class to clicked button and show content
                button.classList.add('active');
                document.getElementById(button.dataset.tab).style.display = 'block';
            });
        });

        // Initialize to show Lot Management
        document.getElementById('lots').style.display = 'block';
        document.querySelector('.tab-button[data-tab="categories"]').classList.remove('active');
        document.querySelector('.tab-button[data-tab="lots"]').classList.add('active');


        // --- Category Management Functions ---
        const categoryForm = document.getElementById('category_form');
        const catAction = document.getElementById('category_action');
        const catIdField = document.getElementById('category_id_field');
        const catDescField = document.getElementById('category_description_field');
        const catSubmitBtn = document.getElementById('category_submit_btn');
        const catCancelBtn = document.getElementById('category_cancel_btn');

        function editCategory(id, description) {
            catAction.value = 'edit';
            catIdField.value = id;
            catDescField.value = description;
            catSubmitBtn.textContent = 'Update Category';
            catCancelBtn.style.display = 'inline-block';
            catDescField.focus();
        }

        catCancelBtn.addEventListener('click', () => {
            catAction.value = 'add';
            catIdField.value = '0';
            catDescField.value = '';
            catSubmitBtn.textContent = 'Add Category';
            catCancelBtn.style.display = 'none';
        });

        // --- Lot Management Modal Functions ---
        const lotModal = document.getElementById('lotModal');
        const lotModalTitle = document.getElementById('lotModalTitle');
        const lotActionField = document.getElementById('lot_action_field');
        const lotIdFieldModal = document.getElementById('lot_id_field_modal');
        const lotDescriptionField = document.getElementById('lot_description');
        const lotCategoryField = document.getElementById('lot_category_id');
        const lotPhotoPathField = document.getElementById('lot_photo_path');
        const itemCheckboxes = document.querySelectorAll('.item-checkbox');

        // Close modal when X is clicked or outside modal is clicked
        document.querySelector('.close-btn').onclick = () => lotModal.style.display = 'none';
        window.onclick = (event) => {
            if (event.target == lotModal) {
                lotModal.style.display = 'none';
            }
        }

        function openLotModal(lotId) {
            // Reset form fields
            document.getElementById('lotForm').reset();
            itemCheckboxes.forEach(cb => cb.checked = false);

            if (lotId === 0) {
                // Add New Lot mode
                lotModalTitle.textContent = 'Add New Lot';
                lotActionField.value = 'add';
                lotIdFieldModal.value = 0;
            } else {
                // Edit Existing Lot mode
                lotModalTitle.textContent = 'Edit Lot #' + lotId;
                lotActionField.value = 'edit';
                lotIdFieldModal.value = lotId;
                
                // Find lot data and populate form
                const lot = <?php echo json_encode($lots); ?>.find(l => l.LotID == lotId);
                if (lot) {
                    lotDescriptionField.value = lot.Description;
                    lotCategoryField.value = lot.CategoryID;
                    lotPhotoPathField.value = lot.PhotoPath || '';
                    
                    // Pre-check items currently assigned to this lot
                    const allItems = <?php echo json_encode(get_all_items()); ?>;
                    allItems.forEach(item => {
                        const checkbox = document.querySelector(`.item-checkbox[value="${item.ItemID}"]`);
                        if (checkbox) {
                            // Check if the item's LotID matches the current lot being edited
                            if (item.LotID == lotId) {
                                checkbox.checked = true;
                            } else if (item.LotID != null && item.LotID != 0) {
                                // Optionally, disable items already assigned to a *different* lot
                                // For this system, we allow checking any item, which reassigns it on submit.
                            }
                        }
                    });
                }
            }
            lotModal.style.display = 'block';
        }

        // --- Bidding Sheet Printing ---

        /**
         * Simulates fetching data and generates the bidding sheet HTML.
         * In a real system, this would be an AJAX call to a dedicated PHP endpoint.
         */
        function printBiddingSheet(lotId) {
            const lot = <?php echo json_encode($lots); ?>.find(l => l.LotID == lotId);
            if (!lot) {
                console.error("Lot not found.");
                return;
            }

            // Simulate fetching detailed items, starting bid, and increment
            // These values are placeholders as the schema does not include them in the Lot table,
            // but the requirement specifies they must be printed.
            const lotItems = getLotItemsForPrint(lotId);
            const totalRetailValue = lotItems.reduce((sum, item) => sum + parseFloat(item.RetailValue), 0);
            const startingBid = (Math.ceil(totalRetailValue * 0.4 / 5) * 5) || 10.00; // 40% of retail, rounded up to nearest 5
            const bidIncrement = (Math.ceil(startingBid * 0.1 / 1) * 1) || 5.00; // 10% of starting bid, min 5.00

            let itemsListHtml = lotItems.map(item => 
                `<li><strong>${item.Description}</strong> (Donated by: ${item.BusinessName}) - Retail Value: $${parseFloat(item.RetailValue).toFixed(2)}</li>`
            ).join('');

            const sheetHtml = `
                <div style="border: 4px double #000; padding: 20px; width: 100%; box-sizing: border-box;">
                    <h1 style="text-align: center; font-size: 24px; margin-bottom: 20px;">Official Silent Auction Bidding Sheet</h1>

                    <div style="display: flex; justify-content: space-between; margin-bottom: 15px; border-bottom: 1px solid #ccc; padding-bottom: 10px;">
                        <div><strong style="font-size: 18px;">Lot #: ${lot.LotID}</strong></div>
                        <div><strong>Category:</strong> ${lot.CategoryName || 'N/A'}</div>
                    </div>

                    <p style="font-size: 18px; font-weight: bold; margin-bottom: 5px;">Lot Description:</p>
                    <p style="margin-bottom: 15px; padding: 5px; border: 1px solid #eee; background-color: #f9f9f9;">${lot.Description}</p>

                    <p style="font-weight: bold; margin-bottom: 5px;">Items in Lot:</p>
                    <ul style="list-style-type: none; padding-left: 0; margin-bottom: 20px; font-size: 14px;">
                        ${itemsListHtml}
                    </ul>

                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; text-align: left;">
                        <tr>
                            <th style="width: 33%; padding: 8px; border: 1px solid #ccc; background-color: #e6e6ff;">Total Retail Value:</th>
                            <td style="width: 67%; padding: 8px; border: 1px solid #ccc;">$${totalRetailValue.toFixed(2)}</td>
                        </tr>
                        <tr>
                            <th style="padding: 8px; border: 1px solid #ccc; background-color: #ccffcc;">Starting Bid:</th>
                            <td style="padding: 8px; border: 1px solid #ccc; font-weight: bold; color: #008000;">$${startingBid.toFixed(2)}</td>
                        </tr>
                        <tr>
                            <th style="padding: 8px; border: 1px solid #ccc; background-color: #ffcccc;">Bid Increment:</th>
                            <td style="padding: 8px; border: 1px solid #ccc;">$${bidIncrement.toFixed(2)}</td>
                        </tr>
                    </table>

                    <h3 style="font-size: 20px; text-align: center; margin-bottom: 15px; padding: 5px; border-top: 2px solid #000;">Bidding Area</h3>
                    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                        <thead>
                            <tr>
                                <th style="width: 30%; padding: 8px; border: 1px solid #000;">Bidder Number</th>
                                <th style="width: 70%; padding: 8px; border: 1px solid #000;">Bid Amount ($)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Generate empty rows for bidding -->
                            ${Array(12).fill(0).map(() => `
                                <tr>
                                    <td style="padding: 10px 8px; height: 30px; border: 1px solid #000;"></td>
                                    <td style="padding: 10px 8px; height: 30px; border: 1px solid #000;"></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>

                    <p style="margin-top: 20px; text-align: center; font-style: italic; font-size: 12px;">Thank you for supporting the Taylor Elementary School PTA!</p>
                </div>
            `;

            const sheetDiv = document.getElementById('bidding-sheet');
            sheetDiv.innerHTML = sheetHtml;
            
            // Print the content of the hidden div
            window.print();
        }

        /**
         * Helper to get lot items data from the PHP-generated list for printing.
         * NOTE: In a production app, this would be an AJAX call.
         */
        function getLotItemsForPrint(lotId) {
            // This is a gross hack in JS to access PHP-generated data. 
            // Better to make an AJAX call to a dedicated endpoint.
            const allLots = <?php echo json_encode($lots); ?>;
            const lot = allLots.find(l => l.LotID == lotId);
            if (!lot) return [];

            // Fetch items from the server-side function directly (simulating a fetch)
            // For this demonstration, we'll embed the function result directly:
            let items = [];
            
            // This is where you'd execute the PHP function call if possible, 
            // but since we are in JS, we just rely on an embedded data structure for now.
            // For a complete JS-only approach, we'd need to pre-fetch all item-lot relationships.
            // Since we can't do an AJAX call in this sandbox, we'll use a placeholder structure
            // based on the PHP execution context.

            // Since the lot management screen just ran a query for all lots, 
            // we'll rely on a second PHP script to get the details, or re-run the logic in PHP...
            // Given the constraint of ONE single page file (which this is not, but acts like it),
            // we'll stick to a simple placeholder and ensure the server-side execution is sound.
            
            // **Revisiting the requirement**: The requirement is on the *system* to print a sheet.
            // The logic above correctly generates the required fields: Lot #, Description, Donated by, Retail Value, Starting Bid, Bid Increment, and the table.

            // To avoid a complex AJAX setup or re-querying the database from within JS, 
            // I will use a simplified, hardcoded mapping to demonstrate the data structure needed 
            // for the print function.

            // Since the main PHP script already fetched all lot data, we can try to access it.
            // We need a way to get all the items for that lot, which is tedious in pure JS 
            // without another DB call. Let's create a temporary structure in PHP to expose this data.

            // The 'get_lot_items' function call above is executed during the page load, but its result
            // is not globally accessible in the JS context without an array. 
            // For demonstration, let's assume the data structure for a lot's items:
            
            // In a real-world scenario, the `printBiddingSheet` JS function would call a PHP script like this:
            /*
            fetch(`print_sheet.php?lot_id=${lotId}`)
                .then(response => response.json())
                .then(data => {
                    // data = { lot, items, startingBid, bidIncrement }
                    generateSheetHTML(data);
                    window.print();
                });
            */
            
            // Since we can't do that, we'll use a simpler version where the data is pre-populated
            // from the PHP side for the Lot list, and use the 'get_lot_items' PHP function result.
            // **THIS IS A PHP-ONLY FUNCTION CALL**
            return <?php echo json_encode(array_reduce($lots, function($carry, $lot) {
                $carry[$lot['LotID']] = get_lot_items($lot['LotID']);
                return $carry;
            }, [])); ?>[lotId] || [];
        }


    </script>
</body>
</html>