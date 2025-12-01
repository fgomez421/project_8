<?php
// Include database configuration
include_once 'db_config.php';

// --- FUNCTIONS ---

/**
 * Fetches all lots with category and item details.
 * @param string $search_term Optional term to search in lot or item description.
 * @param int $category_id Optional ID to filter by category.
 * @return array Array of lot data.
 */
function get_filtered_lots($search_term = '', $category_id = 0) {
    global $conn;
    
    // Base query to select lot and category information
    $sql = "
        SELECT 
            L.LotID, L.Description AS LotDescription, L.PhotoPath, 
            C.Description AS CategoryName, C.CategoryID
        FROM Lot L
        LEFT JOIN Category C ON L.CategoryID = C.CategoryID
    ";
    
    $where_clauses = [];
    
    // Add category filter
    if ($category_id > 0) {
        $where_clauses[] = "L.CategoryID = " . intval($category_id);
    }

    // Add search filter (searches Lot Description OR Item Descriptions)
    if (!empty($search_term)) {
        $safe_search = $conn->real_escape_string($search_term);
        $search_condition = "(L.Description LIKE '%$safe_search%' OR L.LotID IN (
            SELECT DISTINCT LotID FROM Item 
            WHERE Description LIKE '%$safe_search%' AND LotID IS NOT NULL
        ))";
        $where_clauses[] = $search_condition;
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(' AND ', $where_clauses);
    }
    
    $sql .= " ORDER BY C.Description, L.LotID";

    $result = $conn->query($sql);
    $lots = [];
    while ($row = $result->fetch_assoc()) {
        $lots[$row['LotID']] = $row;
    }

    // Now fetch all items for all retrieved lots to fulfill the item listing requirement
    $lot_ids = array_keys($lots);
    if (empty($lot_ids)) {
        return [];
    }
    
    $item_sql = "
        SELECT 
            I.LotID, I.Description AS ItemDescription, I.RetailValue, 
            D.BusinessName AS DonorName
        FROM Item I
        LEFT JOIN Donor D ON I.DonorID = D.DonorID
        WHERE I.LotID IN (" . implode(',', $lot_ids) . ")
        ORDER BY I.LotID
    ";
    
    $item_result = $conn->query($item_sql);
    while ($item_row = $item_result->fetch_assoc()) {
        $lotId = $item_row['LotID'];
        if (!isset($lots[$lotId]['Items'])) {
            $lots[$lotId]['Items'] = [];
        }
        $lots[$lotId]['Items'][] = $item_row;
        
        // Calculate total retail value
        $lots[$lotId]['TotalRetailValue'] = ($lots[$lotId]['TotalRetailValue'] ?? 0) + $item_row['RetailValue'];
    }
    
    return $lots;
}

// --- LOGIC ---

$selected_category = isset($_GET['category']) ? intval($_GET['category']) : 0;
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$view_lot_id = isset($_GET['view']) ? intval($_GET['view']) : 0;

// Fetch all categories for the browse filter
$categories_result = $conn->query("SELECT * FROM Category ORDER BY Description");
$categories = $categories_result->fetch_all(MYSQLI_ASSOC);

$filtered_lots = [];
$current_lot = null;

if ($view_lot_id > 0) {
    // If viewing a single lot, fetch only that lot's details
    $temp_lots = get_filtered_lots('', 0); // Re-run query without general filtering
    $current_lot = $temp_lots[$view_lot_id] ?? null;
} else {
    // Otherwise, fetch the list view
    $filtered_lots = get_filtered_lots($search_term, $selected_category);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Silent Auction Lots - Taylor Elementary PTA</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .container { max-width: 1200px; }
        .lot-card {
            transition: transform 0.2s;
        }
        .lot-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        .lot-image {
            object-fit: cover;
            width: 100%;
            height: 200px;
            border-radius: 8px 8px 0 0;
        }
    </style>
</head>
<body class="bg-gray-50 font-sans leading-normal tracking-normal">

    <div class="container mx-auto p-4">
        <header class="bg-white p-6 rounded-lg shadow-lg mb-6">
            <h1 class="text-4xl font-extrabold text-indigo-700 mb-2">Taylor Elementary Silent Auction</h1>
            <p class="text-gray-600">Browse the incredible lots up for bid!</p>
        </header>

        <?php if ($view_lot_id > 0 && $current_lot): ?>
            <!-- Lot Detail Page -->
            <div class="bg-white p-8 rounded-lg shadow-xl border border-indigo-200">
                <a href="browse_auction.php" class="text-indigo-600 hover:text-indigo-800 font-semibold mb-4 inline-block">&larr; Back to all Lots</a>
                
                <h2 class="text-3xl font-bold text-gray-800 mb-6 border-b pb-2">Lot #<?php echo htmlspecialchars($current_lot['LotID']); ?>: <?php echo htmlspecialchars($current_lot['LotDescription']); ?></h2>

                <div class="md:flex md:space-x-8">
                    <div class="md:w-1/2 mb-6 md:mb-0">
                        <img src="<?php echo htmlspecialchars($current_lot['PhotoPath'] ?? 'https://placehold.co/600x400/A5B4FC/3730A3?text=Lot+Photo'); ?>" 
                             alt="Photo of Lot #<?php echo htmlspecialchars($current_lot['LotID']); ?>" 
                             class="rounded-lg shadow-lg w-full">
                    </div>

                    <div class="md:w-1/2">
                        <p class="text-lg text-indigo-600 font-semibold mb-4">Category: <?php echo htmlspecialchars($current_lot['CategoryName'] ?? 'Uncategorized'); ?></p>

                        <div class="bg-indigo-50 p-4 rounded-lg mb-6">
                            <p class="text-2xl font-bold text-gray-800">Total Estimated Retail Value:</p>
                            <p class="text-3xl text-green-600 font-extrabold">$<?php echo number_format($current_lot['TotalRetailValue'] ?? 0, 2); ?></p>
                            <p class="text-sm text-gray-500 mt-1">Starting Bid and Increment determined by committee.</p>
                        </div>

                        <h3 class="text-2xl font-semibold mb-3 border-b pb-1">Items Included in Lot:</h3>
                        <?php if (!empty($current_lot['Items'])): ?>
                            <ul class="space-y-3">
                                <?php foreach ($current_lot['Items'] as $item): ?>
                                <li class="p-3 bg-gray-100 rounded-lg shadow-sm">
                                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($item['ItemDescription']); ?></p>
                                    <p class="text-sm text-gray-600">
                                        Donated by: <strong><?php echo htmlspecialchars($item['DonorName'] ?? 'Anonymous'); ?></strong> 
                                        (Retail Value: $<?php echo number_format($item['RetailValue'], 2); ?>)
                                    </p>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-red-500">No items have been assigned to this lot yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Lot List/Search Page -->
            
            <!-- Search and Filter Bar -->
            <div class="bg-white p-6 rounded-lg shadow-lg mb-8">
                <form method="GET" action="browse_auction.php" class="flex flex-col md:flex-row space-y-4 md:space-y-0 md:space-x-4">
                    <!-- Search Input -->
                    <input type="text" name="search" placeholder="Search by description (e.g., tickets, gift certificate)" 
                           value="<?php echo htmlspecialchars($search_term); ?>"
                           class="flex-grow p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">

                    <!-- Category Filter -->
                    <select name="category" class="p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 w-full md:w-64">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['CategoryID']; ?>" 
                                <?php echo $selected_category == $cat['CategoryID'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['Description']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-6 rounded-lg transition duration-150">
                        Filter/Search
                    </button>
                    <?php if (!empty($search_term) || $selected_category > 0): ?>
                        <a href="browse_auction.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-3 px-6 rounded-lg text-center transition duration-150">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <h2 class="text-2xl font-bold text-gray-700 mb-4">
                <?php 
                    if (!empty($search_term)) {
                        echo "Search Results for: '" . htmlspecialchars($search_term) . "'";
                    } else if ($selected_category > 0) {
                        $cat_name = array_filter($categories, fn($c) => $c['CategoryID'] == $selected_category);
                        $cat_name = array_values($cat_name)[0]['Description'] ?? 'Category';
                        echo "Lots in Category: " . htmlspecialchars($cat_name);
                    } else {
                        echo "All Available Lots";
                    }
                ?>
            </h2>

            <!-- Lot Grid -->
            <?php if (!empty($filtered_lots)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <?php foreach ($filtered_lots as $lot): 
                        $retail_value = number_format($lot['TotalRetailValue'] ?? 0, 2);
                        $item_count = count($lot['Items'] ?? []);
                    ?>
                    <div class="lot-card bg-white rounded-lg shadow-xl overflow-hidden border border-gray-200">
                        <img src="<?php echo htmlspecialchars($lot['PhotoPath'] ?? 'https://placehold.co/600x400/E0E7FF/4338CA?text=Lot+Photo'); ?>" 
                             alt="Lot photo" 
                             class="lot-image">
                        
                        <div class="p-5">
                            <p class="text-sm font-semibold text-indigo-600 mb-1"><?php echo htmlspecialchars($lot['CategoryName'] ?? 'Uncategorized'); ?></p>
                            <h3 class="text-xl font-bold text-gray-900 truncate mb-2">Lot #<?php echo htmlspecialchars($lot['LotID']); ?>: <?php echo htmlspecialchars($lot['LotDescription']); ?></h3>
                            
                            <div class="flex justify-between items-center text-sm mb-4">
                                <span class="text-gray-600"><?php echo $item_count; ?> Item(s)</span>
                                <span class="font-bold text-green-600">Retail Value: $<?php echo $retail_value; ?></span>
                            </div>

                            <a href="browse_auction.php?view=<?php echo $lot['LotID']; ?>" 
                               class="block w-full text-center bg-indigo-500 hover:bg-indigo-600 text-white font-bold py-2 rounded-lg transition duration-150">
                                View Details & Bid
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-lg" role="alert">
                    <p class="font-bold">No Lots Found</p>
                    <p>There are no lots matching your current search or category filter. Try clearing the filters.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>

</body>
</html>