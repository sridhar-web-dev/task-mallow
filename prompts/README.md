# Prompt log

The application was built with Claude Code, in the Claude desktop app, using Claude
Opus 5.5. Screenshots of the actual prompts belong in this folder.

## How the work was run

1. The full brief went in first, with the instruction to work one step at a time and
   wait for approval.
2. A 10-step plan was proposed and approved before any code was written.
3. Each step ended with what changed, what was verified and any open decisions, and the
   next step started only after approval.
4. Billing rules and dashboard rules were proposed in writing and approved before the
   code for them was written.

## Where human judgement changed the work

Replace this section with your own examples. These came up during the build:

- Git was deferred at the start of the project.
- Each step's approval was a real checkpoint for the proposed rules.

## Where verification corrected the AI's first attempt

Each of these came from running the code, not from reading it:

| Step | What happened |
|---|---|
| 2 | SQLite rejected the `ascii_bin` collation, so it became MariaDB-only |
| 4 | The rate limiter ran before authentication, and failed all 25 endpoint tests |
| 4 | The proposed 35-day late window could not protect invoices; it became 3 days, matched by a 3-day wait before invoicing |
| 6 | Laravel's `date` cast stored a time on SQLite, so a date-only cast was added before it could affect comparisons |
| 8 | A query that passed on SQLite returned a `500` on MariaDB |
| 9 | The 50L run exposed a full index scan and repeated cache reads in the dashboard |

## Screenshots to add

- [ ] The first message with the brief
- [ ] The step plan and approval
- [ ] Billing rules approved before Step 3
- [ ] Dashboard rules approved before Step 8
- [ ] The Step 9 load test and bug fixes
