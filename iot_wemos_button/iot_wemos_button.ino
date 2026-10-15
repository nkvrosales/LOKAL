/*
  Lokal store open/closed button for a Wemos D1 mini (ESP8266).

  Wiring:
    Push button: D5 to GND (uses the internal pull-up resistor)
    Status LED:  D6 -> 220-330 ohm resistor -> LED anode; LED cathode -> GND

  Generate a device token in the store account profile and enter it below.
  Use the computer's LAN IP and this project's URL in API_URL. The computer
  and Wemos must be on the same Wi-Fi network for a local XAMPP installation.
*/
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>

const char *WIFI_SSID = "YOUR_WIFI_NAME";
const char *WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
const char *DEVICE_TOKEN = "PASTE_GENERATED_64_CHARACTER_TOKEN";
const char *API_URL = "http://192.168.1.10/lokal/iot_store_status.php";

const uint8_t BUTTON_PIN = D5;
const uint8_t LED_PIN = D6;
const unsigned long DEBOUNCE_MS = 40;

WiFiClient wifiClient;
bool storeIsOpen = true;
bool stableButton = HIGH;
bool lastReading = HIGH;
unsigned long lastChangeMs = 0;
unsigned long lastSyncMs = 0;

void setStatusLed() {
  digitalWrite(LED_PIN, storeIsOpen ? HIGH : LOW);
}

bool sendStatus(bool isOpen) {
  HTTPClient http;
  if (!http.begin(wifiClient, API_URL)) {
    Serial.println("Could not start HTTP request");
    return false;
  }
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Token", DEVICE_TOKEN);
  String body = String("{\"is_open\":") + (isOpen ? "true}" : "false}");
  int code = http.POST(body);
  String response = http.getString();
  http.end();
  Serial.printf("Status update HTTP %d: %s\n", code, response.c_str());
  return code >= 200 && code < 300;
}

bool syncStatus() {
  HTTPClient http;
  if (!http.begin(wifiClient, API_URL)) return false;
  http.addHeader("X-Device-Token", DEVICE_TOKEN);
  int code = http.GET();
  if (code == 200) {
    String response = http.getString();
    storeIsOpen = response.indexOf("\"is_open\":true") >= 0;
    setStatusLed();
    Serial.printf("Store is %s\n", storeIsOpen ? "OPEN" : "CLOSED");
    http.end();
    return true;
  } else {
    Serial.printf("Could not read store status (HTTP %d)\n", code);
  }
  http.end();
  return false;
}

void setup() {
  Serial.begin(115200);
  pinMode(BUTTON_PIN, INPUT_PULLUP);
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("Connecting to Wi-Fi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.printf("\nConnected. Wemos IP: %s\n", WiFi.localIP().toString().c_str());
  syncStatus();
  lastSyncMs = millis();
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    WiFi.reconnect();
    delay(100);
    return;
  }

  // Keep the LED and local state aligned with changes made in the web profile.
  if (millis() - lastSyncMs >= 3000) {
    syncStatus();
    lastSyncMs = millis();
  }

  bool reading = digitalRead(BUTTON_PIN);
  if (reading != lastReading) {
    lastChangeMs = millis();
    lastReading = reading;
  }

  if (millis() - lastChangeMs >= DEBOUNCE_MS && reading != stableButton) {
    stableButton = reading;
    if (stableButton == LOW) { // button pressed
      if (!syncStatus()) {
        Serial.println("Cannot read current store status; press again when connected.");
        delay(5);
        return;
      }
      bool requestedStatus = !storeIsOpen;
      if (sendStatus(requestedStatus)) {
        storeIsOpen = requestedStatus;
        setStatusLed();
      } else {
        Serial.println("Update failed; LED/status left unchanged. Press again to retry.");
      }
    }
  }
  delay(5);
}
