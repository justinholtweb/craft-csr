---
title: FAQ
slug: faq
order: 50
summary: The questions that come up before putting a help centre and a support desk on a live site.
---

**Why are articles and tickets elements?**

Because Craft already solves most of what a help desk needs. Articles get your own field layouts,
per-site URLs, Craft's search, relations, permissions, the trash, a proper element index and the
exporter. A plugin that stored articles in its own tables would need its own version of each, and
none of them would be as good. Tickets are elements too, with a sequential ticket number so a
customer isn't told they're ticket 4,182,001.

**What can't I do on Lite?**

Nothing that a one-person desk needs. Lite has no limit on articles, categories or tickets, and it
includes the help centre, search, ratings, the suggestions before the form, replies, internal notes
and the top-searches report. Pro adds measurement (failed searches, article performance, deflection
and desk reports) and scale (SLAs, teams, canned replies, custom form questions, restricted
articles, the satisfaction survey, merging and export). Lite allows five ticket statuses and keeps
30 days of search history.

**Do customers need an account to raise a ticket?**

No, and most won't have one. The customer's name and email are stored on the ticket, and the link
in every email opens their own ticket page, where they can read it and reply. If someone signed in
raises a ticket, it is also linked to their account, and `craft.csr.myTickets()` lists their
tickets. To require sign-in, turn off **Anyone can raise a ticket**.

**Does it need Pigeon?**

No. CSR works on its own and never loads Pigeon's code. If Pigeon is installed and you turn on the
bridge, each ticket also opens a Pigeon thread and public replies are copied into it. If the bridge
fails, the ticket is still created and a warning is logged. See [Usage](usage#pigeon).

**How is deflection measured?**

By asking. "They went away" and "they were helped" look the same to the server, so a deflection
rate based on people leaving mostly measures how often visitors got distracted. When CSR suggests
articles on the support form, it records a session for that visitor, and the outcome is set by what
they do. **Yes, that answers it** counts as deflected, and sending the form counts as raising a
ticket. The rate is deflected ÷ (deflected + raised). A session with no decision after an hour is
marked abandoned and counted in neither half. The suggestions are in Lite. The report is Pro.

**What does "pending" do to the SLA clock?**

It pauses the clock. It doesn't stop it, and it doesn't keep it running. If waiting time counted
against the desk, every policy would be breached by customers who reply slowly, and the SLA report
would measure customers instead of the desk. If the clock stopped, a ticket could sit in "pending"
forever with a clean record. When a ticket enters any status of the Pending kind, CSR records the
time. When the ticket leaves that status, or the customer replies (which moves it back to Open
automatically), the deadlines move forward by the time spent waiting. If the policy only counts
opening hours, only opening hours are added back.

**Does it work on a multi-site install?**

Yes. Articles are localized: each site has its own URI format, template and default enabled state,
and each article can have its own title and content per site. Search, suggestions and reports can be
filtered by site. Tickets are not localized. Each ticket records the site it came from, and the
customer's link points back to that site.

**Can I use my own templates?**

Yes, one page at a time. Copy any file from CSR's `templates` folder into `templates/csr/` in your
site, keeping the same path, and CSR uses your copy. You don't need an overrides file or any
configuration, and you don't need to copy every template to change one. See
[Usage](usage#taking-over-the-templates).

**Is anything deleted automatically?**

Only what you tell it to delete. **Delete tickets after** is off by default. When you set it,
resolved and closed tickets older than that are permanently deleted, not moved to the trash,
because the point of a retention period is that the data is really gone. Searches are kept for
**Keep searches for** (365 days by default, and 30 on Lite). Suggestion sessions are deleted on the
same schedule, except ones linked to a ticket. Article view rows are trimmed after a year or more. Searches
and suggestion sessions identify the visitor only by a salted hash. Tickets store the sender's IP
address unless you turn off **Store the sender's IP address**.

**How much is it?**

Lite is free. Pro is $99, then $79 a year for updates.
