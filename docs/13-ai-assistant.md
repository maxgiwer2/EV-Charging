# 13 AI Assistant

How the assistant satisfies FR-017 ("answer from system data only; include
source records") and the architecture rule that **AI is advisory while
deterministic business rules remain authoritative**.

## Why the design is shaped this way

That rule is not stylistic. Asked a plain division — 1385.84 ÷ 3, where the
answer is 461.95 — the three models available on the configured Ollama server
returned:

| Model | Answer |
|---|---|
| `scb10x/llama3.1-typhoon2-8b-instruct` | 4116.52 — misread the question *and* got the multiplication wrong |
| `gemma4:12b` | empty response |
| `llama3.1:8b` | 461.95 ✓ |

One in three. A figure produced that way must never reach a financial answer,
so the assistant is built so the model *cannot* contribute a number.

## Pipeline

```
question
   │
   ├─ 1. model  → structured intent {period, dimension, intent}
   │             every field whitelisted; anything else falls back to a default
   │
   ├─ 2. AnalyticsService → the figures
   │             same code as dashboard and exports, so answers reconcile (AT-009)
   │
   └─ 3. model  → a sentence around those figures
                 rejected if it contains a number the application did not compute
```

Step 2 is the only source of numbers. Steps 1 and 3 are language work.

## The guard

`containsOnlyKnownNumbers()` extracts every numeral from the narration and
requires each to appear among the computed facts. A sentence that invents a
figure is discarded and `answer` returns `null`; the client then shows the
figures alone. **A wrong sentence is worse than no sentence.**

Small integers (≤ 31, no decimal point) are exempt — they appear as counts,
dates and ordinals in ordinary prose, and flagging them would reject nearly
every valid answer. Money and energy figures always carry decimals or exceed
that range, so the values that matter are still covered.

Rejections are logged (without the question, which is private financial
enquiry — docs/10 rule 13) so a rising rate signals the prompt or model needs
revisiting.

## Scoping

The filter is built from the authenticated user, never from the question. A
non-admin is pinned to their own records, so the assistant cannot be used to
get around the ownership rules that guard every other endpoint (AT-007). Only
`CONFIRMED` sessions are counted, so an answer always agrees with the dashboard.

## Endpoint

```
POST /api/v1/assistant/ask   { "question": "..." }
```

```json
{
  "data": {
    "answer": "เดือนนี้ใช้เงินชาร์จไป 1385.84 บาท ...",
    "facts": { "currency": "THB", "total_spend": "1385.84", "cost_per_kwh": "7.8875", ... },
    "sources": { "computed_by": "AnalyticsService", "session_count": 5, "filter": {...} }
  },
  "meta": { "provider": "ollama", "model": "llama3.1:8b", "advisory": true }
}
```

`advisory: true` is stated explicitly so a client never presents the sentence as
authoritative. The figures are; the sentence is not. Throttled to 10/minute
separately from the general API budget, because a local model takes seconds per
call.

## Configuration

```
AI_DRIVER=ollama
OLLAMA_BASE_URL=http://<ollama-host>:11434/v1     # include the /v1 suffix
OLLAMA_MODEL=llama3.1:8b
```

Self-hosted deliberately: the assistant discusses a user's financial records,
and this way none of that data leaves the deployment. The default driver is
`none`, so a checkout without a server still works — the endpoint returns the
computed facts with `answer: null`.

Currency is stated in the facts as `THB`. Left to infer, models reported baht
figures as dollars, which on a financial answer is simply wrong.

## Known limitations

- **The guard stops wrong numbers, not unhelpful ones.** In live testing
  `llama3.1:8b` correctly reported the total in Thai but then claimed cost/kWh
  could not be calculated when the fact was present. No safety rule was broken —
  it just failed to use a value it was given. The facts accompany every answer
  for exactly this reason.
- ~20 seconds per question on the configured hardware; two model calls per
  question (classify, then narrate).
- Only period and dimension are extracted from a question. Comparisons
  ("cheaper than last month?") are not yet modelled.
- Anomaly detection and forecasting (docs/09 M5) are **not implemented**. Both
  should be deterministic and statistical, not LLM work, for the same reason
  the figures above are.
