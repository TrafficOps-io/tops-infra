---
status: accepted
---

# Cloudflare records: adopt, never take over; detect drift, never auto-heal

The Cloudflare module writes into DNS zones that belong to customers and may
hold records the customer manages by hand. We decided that the module modifies
or deletes only records it created itself (managed records). A pre-existing
record that already matches an expectation is adopted and never touched, and a
pre-existing record of the same type and name with different content is a
conflict that stops the reconcile rather than being overwritten. Multi-value
record sets (round-robin A, several TXT) are treated as conflicts and are out
of scope. Likewise, drift found by a check is only reported; nothing
re-reconciles automatically, because a change made by hand may be intentional.
The cost is that some setups need an operator to resolve a conflict manually;
the alternative, taking over records, risks breaking a customer's unrelated
services.
