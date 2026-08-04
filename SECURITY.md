# Security

## Reporting a vulnerability

Use GitHub's private vulnerability reporting: **Security → Report a
vulnerability** on [this repository](https://github.com/hkyss/sonar/security).
It opens a private thread with the maintainer. Please do not open a public issue
for something exploitable.

Tell us what you can reproduce, on which PHP version and which integration, and
what an attacker gets out of it. You will get a first reply within a week.

## Supported versions

The latest `0.x` minor. While the major is `0`, fixes land in a new minor rather
than a patch to the previous one.

## What this tool exposes on purpose

Sonar shows the shape of your database work — table names, statement shapes,
query counts and timings — to whoever can see the overlay. That is the feature.
The security question is never *whether* it exposes that, only *to whom*, so the
defaults refuse the ambiguous cases:

- `gated` without a gate collects nothing and shows nothing. There is no
  fallback gate.
- `SONAR=true` is ignored when the environment is production, because it puts
  the overlay in front of anonymous visitors.
- Statements are stored as fingerprints. Literals are replaced by `?` before
  anything is kept, so bind values do not reach the page or the headers even
  when a driver hands us interpolated SQL.

A report that Sonar shows query data to someone your own gate allowed is working
as designed. A report that it shows anything to someone the gate did **not**
allow, or that it leaks a value the fingerprint should have stripped, is a
vulnerability — please send it.

## In scope

- Any path where the overlay or the `X-Sonar-*` headers reach a viewer the
  configuration did not permit.
- Anything rendered into the page that can escape its context: the payload is
  encoded with `JSON_HEX_TAG` and every value is escaped before it is written
  into the DOM.
- A literal surviving fingerprinting and ending up in the snapshot.
