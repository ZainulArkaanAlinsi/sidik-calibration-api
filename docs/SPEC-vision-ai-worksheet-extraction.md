# Implementation Spec: AI Vision-Based Worksheet Data Extraction

**Project:** Sidik Calibration (backend: `sidik-calibration-api` / Laravel, mobile: `sidik-calibration-mobile` a.k.a. `asmo_mobile` / Flutter)
**Feature:** Replace error-prone traditional OCR with an AI Vision extraction pipeline for reading calibration worksheets in the field.

---

## 1. Context

- Existing record flow: `Customer → Equipment → CalibrationSession → RawMeasurement → GumCalculator → UncertaintyCalculation → Certificate`
- Technicians currently take a photo of a physical/paper calibration worksheet and rely on traditional OCR (Tesseract / ML Kit style text extraction) to populate `RawMeasurement` fields. This OCR step fails/errors frequently on every capture attempt.
- Known real reference dataset for validation: pH Meter, Mettler Toledo Five Easy, S/N `B628755900`, customer `PT Tirta Gracia Semesta Mandiri`, certificate No. `012-CAL-524`. Use this as one of the few-shot / test examples.
- Goal: replace the OCR step with a **vision-capable LLM** call (Anthropic Claude API, multimodal) that returns structured JSON matching the `RawMeasurement` schema, with a mandatory human confirmation step before persisting to the database (this data feeds official certificates, so accuracy + human sign-off is non-negotiable).

## 2. Non-goals

- Do NOT auto-save extracted values without technician confirmation.
- Do NOT change the existing `Customer → Equipment → CalibrationSession → RawMeasurement → GumCalculator → UncertaintyCalculation → Certificate` pipeline — this feature only changes **how `RawMeasurement` gets populated**, not what happens after.
- Do NOT expose this extraction step or its raw output to any role other than the technician who captured the photo.

## 3. Backend Changes (`sidik-calibration-api`)

### 3.1 New endpoint
```
POST /api/raw-measurements/extract-from-photo
```
- Auth: technician-scoped (same auth as existing mobile endpoints).
- Request: multipart form with the worksheet image (jpg/png), plus `calibration_session_id`.
- Response: JSON matching the extraction schema below, plus a `confidence` flag per field.

### 3.2 New service class
Create `App\Services\WorksheetVisionExtractor`:
- Calls Anthropic Claude API (`/v1/messages`) with the image as base64 input and a fixed system/user prompt (see 3.3).
- Model: use a current Claude model string (verify latest available string in the API dashboard before hardcoding — do not assume a hardcoded model name is still current).
- Set `temperature: 0` for consistent, repeatable extraction.
- Force structured output: instruct the model to return **only** valid JSON, no prose, matching the schema exactly. Optionally use Claude's tool-use / JSON schema feature to guarantee shape.
- Parse and validate the response server-side (reject/flag if required fields are missing or out of expected numeric range for the given `Equipment` type).

### 3.3 Prompt template (draft — refine with real worksheet photos)
```
You are extracting structured data from a calibration worksheet photo for [Equipment.type, e.g. "pH Meter"] calibration.

Return ONLY valid JSON matching this exact schema:
{
  "standard_value": [array of numbers, one per calibration point],
  "unit_under_test": [array of numbers, same length],
  "correction": [array of numbers, same length],
  "u95": [array of numbers, same length],
  "env_condition": { "temperature_c": number, "temperature_tolerance": number, "humidity_pct": number, "humidity_tolerance": number },
  "notes": string or null,
  "confidence": { "<field_name>": "high" | "medium" | "low" }
}

Use Indonesian decimal convention (comma as decimal separator) if that's how it's written, but return all numbers in this JSON as standard numeric type (period decimal).
If a field is illegible or missing, set its value to null and mark its confidence as "low".

Reference example (few-shot) — [attach one known-good worksheet photo + its correct JSON output here, using the Tirta Gracia pH Meter cert 012-CAL-524 data as ground truth].
```
- Attach 2–3 real worksheet photos + verified correct JSON as few-shot examples directly in the prompt/message history to anchor accuracy to this project's actual worksheet format.

### 3.4 Logging
- Add a `worksheet_extraction_logs` table: `raw_measurement_id`, `raw_model_response` (JSON), `technician_corrections` (JSON, diff between AI output and what technician actually confirmed), `created_at`.
- Purpose: build a dataset of correction patterns to refine the prompt/few-shot examples over time. Not exposed in any UI beyond internal review.

## 4. Mobile Changes (`sidik-calibration-mobile` / `asmo_mobile`)

### 4.1 Flow
1. Technician takes photo of worksheet (existing camera flow — fix underlying capture/permission issues if that's the source of current OCR errors, separately from this AI swap).
2. App uploads photo to `POST /api/raw-measurements/extract-from-photo`.
3. Show a loading state while extraction runs (expect ~2-5s network round trip).
4. Render a **confirmation screen**: each extracted field shown in an editable input, pre-filled with the AI's value.
   - Fields where `confidence == "low"` are visually flagged (e.g. yellow border/background) so the technician knows to double-check that specific value instead of everything.
5. Technician edits if needed, taps **Confirm** → app submits final values to the normal `RawMeasurement` create/update endpoint (unchanged from current flow).
6. On confirm, backend also writes the diff (AI value vs. final technician value) to `worksheet_extraction_logs`.

### 4.2 UI notes
- Reuse existing form/input widgets already used for manual `RawMeasurement` entry — don't build a parallel input system, just pre-fill it.
- Keep a manual "Retake photo" and "Enter manually instead" fallback button in case extraction fails or the photo is unusable — never block the technician's workflow if the AI step fails.

## 5. Acceptance Criteria

- [ ] Photo capture → structured JSON returned in under ~5s on typical mobile network.
- [ ] Confirmation screen shows all fields editable, low-confidence fields visually flagged.
- [ ] No `RawMeasurement` record is created without explicit technician confirmation.
- [ ] Extraction logs are written for every attempt (success or failure) for later prompt tuning.
- [ ] Manual entry fallback remains fully functional if extraction fails or is skipped.
- [ ] Tested against the known Tirta Gracia pH Meter worksheet (cert 012-CAL-524) with 100% field match before considered done.

## 6. Open Questions (resolve before/during implementation)
- Which Claude model to use for cost/accuracy balance (test Sonnet vs Haiku tier on real worksheet photos)?
- Should extraction run synchronously (blocking UI) or as a background job with push notification when ready?
- Retention policy for `worksheet_extraction_logs` (all data here is customer calibration data — apply the same privacy handling as the rest of the system).
