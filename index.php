<?php
header('Content-Type: text/plain; charset=utf-8');
 
// הגדרת נתיבים לקבצים באותה התיקייה
$jsonFilePath = __DIR__ . '/requests.json';
$logFilePath  = __DIR__ . '/system.log';
 
// פונקציה לייעודית לכתיבת לוגים
function writeToLog($message, $logFilePath) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n" . str_repeat('-', 40) . "\n";
    @file_put_contents($logFilePath, $logMessage, FILE_APPEND);
}
 
// 1. קבלת הפרמטרים מהקריאה
$token = isset($_GET['token']) ? $_GET['token'] : '';
$code = isset($_GET['code']) ? $_GET['code'] : '';
 
// 2. קביעת מספר הטלפון (תמיכה ב-Phone וב-ApiPhone)
$phone = '';
if (!empty($_GET['Phone'])) {
    $phone = $_GET['Phone'];
} elseif (!empty($_GET['ApiPhone'])) {
    $phone = $_GET['ApiPhone'];
}
 
// ניקוי מספר הטלפון מתווים שאינם ספרות
$phoneKey = preg_replace('/[^0-9]/', '', $phone);
 
// תיעוד כניסת פרמטרים ללוג
$incomingParams = json_encode($_GET, JSON_UNESCAPED_UNICODE);
writeToLog("בקשה נכנסת לשרת.\nפרמטרים שהתקבלו: {$incomingParams}", $logFilePath);
 
// בדיקת חובה: האם קיים טוקן?
if (empty($token)) {
    $err = "שגיאה: פרמטר token חסר בבקשה.";
    echo $err;
    writeToLog($err, $logFilePath);
    exit;
}
 
// טעינת קובץ ה-JSON הקיים
$jsonData = [];
if (file_exists($jsonFilePath)) {
    $fileContent = file_get_contents($jsonFilePath);
    $jsonData = json_decode($fileContent, true) ?: [];
}
 
// ==========================================
// ניתוב לפי שלבי הפעולה
// ==========================================
 
if (empty($code)) {
    // ==========================================
    // שלב א': שליחת קוד אימות לטלפון
    // ==========================================
    
    if (empty($phoneKey)) {
        echo "read=m-1125=Phone,,10,9,,Phone,,,,,,,,,";
        writeToLog("שלב א': חסר מספר טלפון, הוחזרה הוראת הקשה למערכת.", $logFilePath);
        exit;
    }
 
    // בניית הפרמטרים לשליחה (כאן בתגובה החוזרת מהם זה מגיע כ-reqId)
    $apiParams = [
        'token' => $token,
        'action' => 'send',
        'callerId' => $phoneKey,
        'validType' => 'CALL'
    ];
 
    $url = "https://www.call2all.co.il/ym/api/ValidationCallerId?" . http_build_query($apiParams);
    writeToLog("שלב א': פנייה ל-API.\nכתובת: {$url}", $logFilePath);
 
    $response = @file_get_contents($url);
    if ($response === false) {
        $err = "שגיאה בשלב א': file_get_contents נכשל.";
        echo $err;
        writeToLog($err, $logFilePath);
        exit;
    }
 
    writeToLog("שלב א': תשובה גולמית מה-API:\n{$response}", $logFilePath);
 
    $data = json_decode($response, true);
    if (!$data) {
        $err = "שגיאה בשלב א': תגובה אינה JSON תקין.";
        echo $err;
        writeToLog($err, $logFilePath);
        exit;
    }
 
    if (isset($data['responseStatus']) && $data['responseStatus'] === 'OK' && isset($data['reqId'])) {
        
        // דריסת זיהוי ישן אם קיים
        if (isset($jsonData[$phoneKey])) {
            unset($jsonData[$phoneKey]);
            writeToLog("זיהוי ישן עבור מספר {$phoneKey} נמחק מהקובץ.", $logFilePath);
        }
        
        // שמירת ה-reqId
        $jsonData[$phoneKey] = $data['reqId'];
        file_put_contents($jsonFilePath, json_encode($jsonData, JSON_PRETTY_PRINT));
 
        echo "read=f-A000=code,,6,4,12,Digits,yes,,,,,,,,";
        writeToLog("שלב א' הסתיים בהצלחה. המזהה נשמר ב-JSON.", $logFilePath);
    } else {
        $msg = isset($data['message']) ? $data['message'] : 'לא צוינה סיבה';
        echo "שגיאה מה-API בשלב השליחה: " . $msg;
        writeToLog("שגיאה מה-API בשלב השליחה: " . $msg, $logFilePath);
    }
 
} else {
    // ==========================================
    // שלב ב': אימות הקוד שהוקש על ידי המשתמש
    // ==========================================
    
    if (empty($phoneKey)) {
        $err = "שגיאה בשלב ב': נשלח קוד אך מספר הטלפון חסר בקריאה.";
        echo $err;
        writeToLog($err, $logFilePath);
        exit;
    }
 
    if (!isset($jsonData[$phoneKey])) {
        $err = "שגיאה בשלב ב': לא נמצא מזהה בקשה שמור עבור הטלפון " . $phoneKey . " ב-JSON.";
        echo $err;
        writeToLog($err, $logFilePath);
        exit;
    }
 
    // שליפת המזהה השמור
    $savedId = $jsonData[$phoneKey];
 
    // תיקון ה-URL: שימוש בפרמטר reId במקום reqId והסרת משתנים מיותרים
    $apiParams = [
        'token'  => $token,
        'action' => 'valid',
        'reId'   => $savedId, // התיקון המדויק שלך!
        'code'   => $code
    ];
 
    $url = "https://www.call2all.co.il/ym/api/ValidationCallerId?" . http_build_query($apiParams);
    writeToLog("שלב ב': פנייה ל-API לצורך אימות.\nכתובת שנשלחה: {$url}", $logFilePath);
 
    $response = @file_get_contents($url);
    if ($response === false) {
        $err = "שגיאה בשלב ב': נכשלה ההתקשרות לשרת לצורך אימות.";
        echo $err;
        writeToLog($err, $logFilePath);
        exit;
    }
 
    writeToLog("שלב ב': תשובה גולמית מה-API:\n{$response}", $logFilePath);
 
    $data = json_decode($response, true);
    if (!$data) {
        $err = "שגיאה בשלב ב': תגובת השרת אינה JSON תקין.";
        echo $err;
        writeToLog($err, $logFilePath);
        exit;
    }
 
    if (isset($data['responseStatus']) && $data['responseStatus'] === 'OK') {
        
        // הסרת הרשומה לאחר הצלחה
        unset($jsonData[$phoneKey]);
        file_put_contents($jsonFilePath, json_encode($jsonData, JSON_PRETTY_PRINT));
        
        echo "OK";
        writeToLog("שלב ב' הסתיים בהצלחה מוחלטת! הקוד אומת, הרשומה נמחקה.", $logFilePath);
    } else {
        $msg = isset($data['message']) ? $data['message'] : 'הקוד שגוי או פג תוקף';
        echo "אימות הקוד נכשל מול ימות המשיח. סיבה: " . $msg;
        writeToLog("אימות נכשל. סיבה: {$msg}. קוד: {$code}, מזהה שנשלח (reId): {$savedId}", $logFilePath);
    }
}
