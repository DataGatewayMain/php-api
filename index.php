<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");

$servername = "srv1391.hstgr.io";
$username = "u858543158_technopour";
$password = "Wvh1z]SL#3";
$dbname = "u858543158_33zBrmCUqoJ7";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    $response['error'] = "Connection failed: " . $conn->connect_error;
    echo json_encode($response);
    exit();
}

ini_set('memory_limit', '512M');

// Sanitize and set table name
$userSpecifiedTableName = isset($_GET["api"]) ? $conn->real_escape_string($_GET["api"]) : 'vector19';

// Check if table exists
$checkTableSql = "SHOW TABLES LIKE '$userSpecifiedTableName'";
$result = $conn->query($checkTableSql);
if ($result->num_rows == 0) {
    $response['error'] = "Table '$userSpecifiedTableName' not found.";
    echo json_encode($response);
    exit();
}


// Function to handle search filters
function handleSearchFilters($conn, &$count_sql, &$sql, $filter, $includeKey, $excludeKey, $valueMappings = [], $tableAlias = '') {
    global $response, $filters, $filtersWithValues; // Use global to access the $response variable, $filters array, and $filtersWithValues

    $includeValues = isset($_GET[$includeKey]) ? explode(',', $_GET[$includeKey]) : [];
    $excludeValues = isset($_GET[$excludeKey]) ? explode(',', $_GET[$excludeKey]) : [];

    $includeValues = array_filter($includeValues);
    $excludeValues = array_filter($excludeValues);

    if (!empty($includeValues) || !empty($excludeValues)) {
        $filtersWithValues = true; // Set flag to true if any filter has values
    }

    $filterWithAlias = $tableAlias ? "$tableAlias.$filter" : $filter;

    if (!empty($includeValues)) {
        $mappedIncludeValues = array_map(function ($value) use ($valueMappings, $conn) {
            return isset($valueMappings[$value]) ? $conn->real_escape_string($valueMappings[$value]) : $conn->real_escape_string($value);
        }, $includeValues);

        $includeValues = array_merge($includeValues, $mappedIncludeValues);
        $includeValues = array_map([$conn, 'real_escape_string'], $includeValues);

        if (in_array($filter, $filters)) {
            if (in_array($filter, ['company_name', 'Industry', 'Employee_Size', 'country', 'job_function', 'job_level'])) {
                $includeConditions = implode("', '", $includeValues);
                $count_sql .= " AND $filterWithAlias IN ('$includeConditions')";
                $sql .= " AND $filterWithAlias IN ('$includeConditions')";
            } else {
                $likeCondition = implode("%' OR $filterWithAlias LIKE '%", $includeValues);
                $count_sql .= " AND ($filterWithAlias LIKE '%$likeCondition%')";
                $sql .= " AND $filterWithAlias REGEXP '[[:<:]](" . implode('|', $includeValues) . ")[[:>:]]'";
            }
        } else {
            $likeCondition = implode("%' OR $filterWithAlias LIKE '%", $includeValues);
            $count_sql .= " AND ($filterWithAlias LIKE '%$likeCondition%')";
            $sql .= " AND $filterWithAlias LIKE '%$likeCondition%'";
        }
    }

    if (!empty($excludeValues)) {
        $excludeValues = array_map([$conn, 'real_escape_string'], $excludeValues);
        $excludeCondition = implode("', '", $excludeValues);
        $count_sql .= " AND $filterWithAlias NOT IN ('$excludeCondition')";
        $sql .= " AND $filterWithAlias NOT IN ('$excludeCondition')";
    }
}

// Sanitize input function
function sanitizeInput($conn, $data) {
    return $conn->real_escape_string(trim($data));
}

// Define filters and mappings
$filters = [
    'first_name', 'last_name', 'email_address', 'company_name', 'company_domain',
    'job_title', 'job_function', 'job_level', 'company_address', 'city', 'state',
    'zip_code', 'country', 'telephone_number', 'employee_size', 'industry',
    'company_link', 'prospect_link', 'pid', 'region', 'email_validation'
];

$filterMappings = ['IT' => 'Information Technology'];

// Select columns for queries
$select_columns = "v.first_name, v.last_name, v.company_name, v.company_domain, v.state, v.country, v.job_title, v.email_address, v.email_validation, v.employee_size, v.prospect_link, v.company_link, v.pid";

// Initialize SQL queries
$sql_total_data = "SELECT $select_columns FROM vector19 v WHERE 1";
$sql_net_new_data = "SELECT $select_columns FROM vector19 v LEFT JOIN $userSpecifiedTableName AS s ON v.Prospect_Link = s.Prospect_Link WHERE 1";
$sql_saved_data = "SELECT s.first_name, s.last_name, s.company_name, s.company_domain, s.state, s.country, s.job_title, s.email_address, s.email_validation, s.employee_size, s.prospect_link, s.company_link, s.pid FROM $userSpecifiedTableName s WHERE 1";

// Initialize COUNT queries
$count_sql_total_data = "SELECT COUNT(*) as total FROM vector19 v WHERE 1";
$count_sql_net_new_data = "SELECT COUNT(*) as total FROM vector19 v LEFT JOIN $userSpecifiedTableName AS s ON v.Prospect_Link = s.Prospect_Link WHERE 1";
$count_sql_saved_data = "SELECT COUNT(*) as total FROM $userSpecifiedTableName s WHERE 1";

$filtersApplied = false;
$filtersWithValues = false; // Flag to check if any filter has values

// Apply filters
foreach ($_GET as $key => $value) {
    if (strpos($key, 'include_') === 0 || strpos($key, 'exclude_') === 0) {
        $filter = str_replace(['include_', 'exclude_'], '', $key);
        handleSearchFilters($conn, $count_sql_total_data, $sql_total_data, $filter, "include_$filter", "exclude_$filter", $filterMappings, 'v');
        handleSearchFilters($conn, $count_sql_net_new_data, $sql_net_new_data, $filter, "include_$filter", "exclude_$filter", $filterMappings, 'v');
        handleSearchFilters($conn, $count_sql_saved_data, $sql_saved_data, $filter, "include_$filter", "exclude_$filter", $filterMappings, 's');

        $filtersApplied = true; // Set flag to true if any filter is present in the URL
    }
}

// Join condition for net_new_data to exclude records where pid is the same between two tables
$sql_net_new_data .= " AND s.pid IS NULL";

// Adding ORDER BY clause to sort by first_name in ascending order
$sql_total_data .= " ORDER BY v.first_name ASC";
$sql_net_new_data .= " ORDER BY v.first_name ASC";
$sql_saved_data .= " ORDER BY s.first_name ASC";

// Pagination setup
$records_per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Execute COUNT queries
$count_result_total_data = $conn->query($count_sql_total_data);
$count_result_net_new_data = $conn->query($count_sql_net_new_data);
$count_result_saved_data = $conn->query($count_sql_saved_data);

// Check COUNT query results
if (!$count_result_total_data || !$count_result_net_new_data || !$count_result_saved_data) {
    $response['error'] = "Count query execution failed: " . $conn->error;
    echo json_encode($response);
    exit();
}

// Fetch COUNT results
$total_records_total_data = $count_result_total_data->fetch_assoc()['total'];
$total_records_net_new_data = $count_result_net_new_data->fetch_assoc()['total'];
$total_records_saved_data = $count_result_saved_data->fetch_assoc()['total'];

$sql_saved_data_before_search = "SELECT s.first_name, s.last_name, s.company_name, s.company_domain, s.state, s.country, s.job_title, s.email_address, s.email_validation, s.employee_size, s.prospect_link, s.company_link, s.pid FROM $userSpecifiedTableName s WHERE 1";

 $result_saved_data_before_search = $conn->query("$sql_saved_data_before_search LIMIT $offset, $records_per_page");
 
  while ($row = $result_saved_data_before_search->fetch_assoc()) {
        $records_saved_data_before_search[] = $row;
    }

if (!$filtersApplied || !$filtersWithValues) {
    // Prepare response with counts only
    $response = [
        'total_count' => $total_records_total_data,
        'net_new_count' => $total_records_total_data - $total_records_saved_data,
        'saved_count' => $total_records_saved_data,
        'saved_data' => $records_saved_data_before_search
    ];
} else {
    // Execute data retrieval queries
    $result_total_data = $conn->query("$sql_total_data LIMIT $offset, $records_per_page");
    $result_net_new_data = $conn->query("$sql_net_new_data LIMIT $offset, $records_per_page");
    $result_saved_data = $conn->query("$sql_saved_data LIMIT $offset, $records_per_page");

    // Check data retrieval query results
    if (!$result_total_data || !$result_net_new_data || !$result_saved_data) {
        $response['error'] = "Query execution failed: " . $conn->error;
        echo json_encode($response);
        exit();
    }

    // Initialize arrays for storing fetched data
    $records_total_data = [];
    $records_net_new_data = [];
    $records_saved_data = [];

    // Fetch data into arrays
    while ($row = $result_total_data->fetch_assoc()) {
        $records_total_data[] = $row;
    }
    while ($row = $result_net_new_data->fetch_assoc()) {
        $records_net_new_data[] = $row;
    }
    while ($row = $result_saved_data->fetch_assoc()) {
        $records_saved_data[] = $row;
    }

    // Calculate total pages for pagination
    $total_pages_total_data = ceil($total_records_total_data / $records_per_page);
    $total_pages_net_new_data = ceil($total_records_net_new_data / $records_per_page);
    $total_pages_saved_data = ceil($total_records_saved_data / $records_per_page);

    // Prepare response with data and counts
    $response = [
        'total_data' => $records_total_data,
        'net_new_data' => $records_net_new_data,
        'saved_data' => $records_saved_data,
        'total_count' => $total_records_total_data,
        'net_new_count' => $total_records_total_data - $total_records_saved_data,
        'saved_count' => $total_records_saved_data,
        'new_count' => $total_records_total_data - $total_records_saved_data,
        'total_pages' => [
            'total_pages_total' => $total_pages_total_data,
            'current_page_total' => $page
        ],
        'net_new_pagination' => [
            'total_pages_net_new' => $total_pages_net_new_data,
            'current_page_net_new' => $page
        ],
        'saved_pagination' => [
            'total_pages_saved' => $total_pages_saved_data,
            'current_page_saved' => $page
        ],
        'pagination' => [
            'current_page' => $page,
            'records_per_page' => $records_per_page
        ]
    ];
}

// Output response as JSON
echo json_encode($response);

// Close database connection
$conn->close();
?>
