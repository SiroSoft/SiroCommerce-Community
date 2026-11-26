# Audit Charter

## 1. Objective

Provide repeatable, evidence-based assurance that SiroCommerce backend changes
meet the BE_V2 specification, data-integrity promises, security requirements,
operational recovery requirements, and release gates.

## 2. Scope

In scope: source, migrations, configuration, APIs, module contracts, provider
adapters, queues, outbox, webhooks, logs/trace redaction, tests, CI scripts,
deployment artifacts, backups, restore rehearsals, and production proof.

Out of scope unless explicitly added to a release record: frontend UX, vendor
infrastructure controls, and third-party provider internal systems.

## 3. Roles

| Role | Responsibility |
|---|---|
| Engineering owner | implementation, unit/integration evidence, remediation |
| Security reviewer | auth, secrets, input, webhook, dependency review |
| Data owner | money, inventory, customer/order integrity |
| Operations owner | queue, backup, restore, incident and drill evidence |
| Release approver | accepts only complete gates or explicit exceptions |
| Auditor | challenges evidence and records independent findings |

The implementer cannot be the sole approver of a critical security or data
integrity exception.

## 4. Audit Levels

| Level | Use | Required review |
|---|---|---|
| L1 | local change/PR | automated gates + peer review |
| L2 | release candidate | control matrix + evidence review |
| L3 | production/dogfood | L2 + MySQL/load/restore/security drills |
| L4 | material change/incident | independent review + risk acceptance |

## 5. Sampling And Retention

- Retain release evidence for the supported product lifetime plus 12 months.
- Retain payment/webhook evidence according to applicable legal/provider rules;
  store hashes and references, not raw secrets.
- Sample all critical controls; sample high controls at every release; sample
  medium controls at least quarterly.
- Re-run a control after any material schema, provider, queue, or auth change.
