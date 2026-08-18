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

---

# Anomaly Detection and Forecasting

Both satisfy docs/02 FR-018, and both are **statistical, not AI** — for the same
reason as everything above. They inform decisions about money, so they have to
be reproducible and explainable. A model that flags a different set of sessions
on each run is not a detector.

## Anomaly detection

`GET /api/v1/insights/anomalies` (`?notify=1` also raises FR-014 alerts)

Modified z-score over the **median absolute deviation**, per user, over
confirmed sessions from the last 12 months. Three measures are judged: cost per
kWh, total amount, and energy.

### Why median and MAD, not mean and standard deviation

A single unusually expensive charge inflates the standard deviation enough to
hide itself — a mean-based detector goes quiet exactly when there is something
to report. The median barely moves. There is a test for this
(`it is not blinded by the outlier it is looking for`): a 5350.00 charge against
a 214.00 baseline is caught and reported as high severity.

### What keeps it from being noise

| Rule | Reason |
|---|---|
| Minimum 8 sessions of history | Below that the median is meaningless and every early session looks extreme |
| Per user, never global | Home-only charging has a different normal from motorway rapid charging |
| Only the high side reported | A cheaper-than-usual charge is good news, not an interruption |
| Sessions missing a measure are skipped | A zero unit cost would look like a free charge and drag the baseline down |
| Drafts and cancellations excluded | Not fact, and never happened (AT-009) |
| One notification per session | Re-running must not spam what the user has seen |

### The zero-spread case

When every past session is identical the MAD is zero and the z-score is
undefined. Rather than going silent — that user is exactly the one for whom a
sudden fourfold charge is obvious — the detector falls back to a relative
comparison, scaled so the same 3.5 threshold governs both paths (50% above the
median scores 3.5).

Findings are **advisory**: an expensive charge is often perfectly legitimate, a
motorway rapid charger on a long trip. The reasons and the baseline travel with
each finding so a person can judge.

## Forecasting

`GET /api/v1/insights/forecast`

A run-rate projection of the current calendar month, in the user's local month
(docs/10 rule 7).

Deliberately not a regression. Personal charging data is a handful of points per
month with no seasonality worth modelling, so a regression would produce a more
precise-looking number without being a more accurate one — and precision that is
not accuracy is exactly what misleads someone budgeting.

### It refuses more often than it answers

| Condition | Result |
|---|---|
| Fewer than 5 elapsed days | `too_early_in_period` |
| Fewer than 3 sessions | `not_enough_sessions` |
| No spend recorded | `no_spend_recorded` |

A month projected from two days is a confident-looking figure with nothing
behind it, and people act on those.

### Caveats are stated, not buried

`caveats` lists what would make the projection unreliable — `early_in_period`,
`few_sessions`, `no_previous_period`, `well_above_previous_period`. A user
comparing a projection against their budget deserves to know it rests on four
days of data rather than discovering it later.

`typical_monthly_spend` is the **median** of recent months, not the mean: one
month with a road trip in it should not redefine typical. Months with no
charging are skipped rather than counted as zero — a month away should not drag
the figure down as though the user drove normally and spent nothing.

Responses carry `advisory: true` so a client never renders a projection as a
commitment.
