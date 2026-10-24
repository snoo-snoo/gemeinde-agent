# Provider und Schlüssel

Unterstützte Treiber, jeweils mit der öffentlich dokumentierten Basisadresse:

| Treiber | Basis | Aufruf |
| --- | --- | --- |
| openai | `https://api.openai.com/v1` | `POST /chat/completions` |
| xai | `https://api.x.ai/v1` | `POST /chat/completions` |
| openrouter | `https://openrouter.ai/api/v1` | `POST /chat/completions` |
| openai_compatible | vom Benutzer gesetzt | `POST /chat/completions` |
| anthropic | `https://api.anthropic.com/v1` | `POST /v1/messages`, Header `x-api-key` und `anthropic-version: 2023-06-01` |
| gemini | `https://generativelanguage.googleapis.com/v1beta` | `POST /models/{model}:generateContent`, Header `x-goog-api-key` |

Der Verbindungstest liest `GET {basis}/models` und speichert keine Schlüssel in der Antwort. Für Gemini ist das die Modellliste derselben Basis.

JSON-Modus wird nur angefordert, wenn das gespeicherte Modell `supports_json_mode` trägt. Ob ein konkretes Modell das kann, steht in der Anbieterdokumentation und wird nicht angenommen.

Schlüssel liegen in `ai_credentials.secret` mit dem Laravel-Cast `encrypted`. Die API liefert `hint` mit den letzten vier Zeichen. Audit-Logs entfernen Felder mit den Namen secret, password, api_key, token und authorization. Löschen ist ein Soft-Delete.

Ein Fallback-Provider ist höchstens einer. Rate-Limits gelten je Provider und Minute über den Cache. Token- und Kostenbudgets hängen an der Agentenkonfiguration.

Geschätzte Kosten entstehen nur, wenn Tokenzahlen vorhanden sind und Preise mit Herkunft `manual` oder `provider` gespeichert wurden. Die Herkunft `unknown` lässt die Kosten leer. Die Oberfläche kennzeichnet geschätzte Centbeträge.

Ohne hinterlegten Schlüssel bleibt die Erzählung aus. Die deterministischen Kennzahlen laufen davon unabhängig.
