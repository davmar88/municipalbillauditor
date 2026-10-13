# Design: screens and layout

This is the first layout for the app. It covers the service flow, four phone screens for households, three web screens for managing agents and businesses, and the landing page. The mockups are on a design canvas: <https://claude.ai/artifact/EswiWTVaWHW7absDnVB7tC> (private until shared from its Share menu).

## Principles

These come from the risks in the [README](../README.md).

- **Every flag shows its evidence.** False alarms burn trust, so each finding has a confidence label ("Likely error", "Worth checking" or "Needs your answer"), the figures behind it, and a way to say it is not an error.
- **The bill is the interface.** Findings are numbered, and the same numbers are marked in orange on the bill itself.
- **The deadline is always visible.** Every flagged bill and open dispute shows the date and the days left.
- **Ask only what we can't know.** The customer is asked about things like outage dates or a change in occupancy, never to re-type what is on the bill.
- **Rules are per municipality.** Every one of South Africa's 257 municipalities has its own dispute window and escalation dates. The customer never has to know which municipality bills them or where to send anything: we read it from the bill.
- **The price comes before the job.** Households pay per job from prepaid credit, so every job shows what it costs and the balance before it runs.
- **Email only.** Disputes go to the municipality by email, from us, on the customer's behalf. There is no WhatsApp anywhere in the flow.
- **POPIA from the first screen.** The upload screen says the bill is used only to check and dispute it, and can be deleted at any time.

## Service flow

One flow serves all three customers. Only how they pay changes: households pay per job from prepaid credit (a bill check and a dispute, R15 and R49 by default), and managing agents and businesses pay one flat monthly fee that includes 100 bill checks and unlimited disputes.

| Step | Who          | What happens                                                                                                                                                            |
| ---- | ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1    | Customer     | Sends the bill by photo, email forward or PDF                                                                                                                           |
| 2    | Bill Auditor | Charges the bill check, or holds the bill as "Waiting for credit" until a top-up                                                                                        |
| 3    | Bill Auditor | Reads meter readings, tariff, charges and the municipality's name and contact details. A failed read is refunded                                                        |
| 4    | Bill Auditor | Matches the municipality to the directory of all 257 and runs the four checks                                                                                           |
| 5    | Customer     | No flags: gets an all-clear. Flags: confirms the facts only they know                                                                                                   |
| 6    | Bill Auditor | Drafts the dispute and sets the deadline from the municipality's rules                                                                                                  |
| 7    | Customer     | Gives a one-time mandate and approves the dispute (or has switched on "send disputes for me")                                                                           |
| 8    | Bill Auditor | Emails it to the best address we have, copying the customer in, with the bill and meter photo attached                                                                  |
| 9    | Bill Auditor | Reads the municipality's replies with Claude and tells the customer what they say                                                                                       |
| 10   | Bill Auditor | No acknowledgement: follows up on the thread. Still no answer: escalates to the municipal manager, then drafts the Public Protector complaint for the customer to lodge |
| 11   | Municipality | Corrects the bill and credits the account                                                                                                                               |
| 12   | Bill Auditor | Finds the credit on a later bill and tells the customer                                                                                                                 |

## Screens

### Households (phone, 390 px wide)

1. **Check a bill.** The main action is photographing the bill, with PDF upload and an email forwarding address as alternatives. Below that are past bills with their status and a short list of the four checks.
2. **Results.** A dark summary block gives the number of likely errors, the rand amount at stake and the dispute deadline. Below it, a compact copy of the bill shows the orange marks. Each finding card has its evidence (for example six months of usage with the estimated month hatched) and one action. A "Checked and fine" list shows what passed.
3. **Dispute letter.** A step indicator, a letter preview, attachments, where it will be emailed, the price and the credit balance, and the one-time mandate checkbox. "Approve and send" emails it.
4. **Case tracker.** A countdown to the reply date, the municipal reference number, a timeline from "Bill checked" to "Credit on your account" with escalation steps marked as "only if needed", every email sent and received with Claude's summary of each reply, a panel when the municipality needs something from the customer, and a reminder to keep paying the undisputed amount.
5. **Credit and billing.** The balance, the price of each job, top-up packs paid by card through Paystack, and the ledger.

Bottom tabs: Bills, Disputes, Property, Credit.

### Managing agents (web)

The web app uses a dark sidebar (232 px) and a fluid content area. On a phone the sidebar stacks above the content.

6. **Portfolio.** Four figures for the billing cycle (bills checked, open flags, deadlines in the next 7 days, credits won this year), a units table sorted by urgency with filter chips, a "Due this week" list, and open flags by type.
7. **Bill audit workspace.** The bill is shown as a document on the left with orange marks. The findings panel is on the right, using the same numbers. Each finding shows its evidence and calculation. Findings that might be a false alarm ask a question first ("Did anything change at this unit in September?"). Twelve-month usage charts for water and electricity sit below.

### Businesses and body corporates (web)

8. **Claim.** The amount claimed, a dated timeline, the tariff before and after the fix with the monthly saving, the emails, and the evidence on file. Claims are included in the monthly fee.
9. **Credit and billing.** The monthly fee and how many of the month's included checks are used, the saved card, invoices with a pay button, prepaid credit for checks beyond the allowance, and the ledger.

### Staff (web)

10. **Work queue.** Disputes approved but not sent (no address, no mandate, or a failed send), managed disputes that need the next step, municipal replies Claude couldn't read or that came from an unknown sender, bills waiting for their details, and EFT top-ups.
11. **Margin.** Each month's top-ups and monthly fees received against what Claude cost us, from the token counts of every call.
12. **Reference data.** All 257 municipalities with their dispute rules, billing and escalation emails (and whether staff have verified them), outages and tariff rates.

### Landing page

13. **Landing page.** A hero with the marked-up bill, a dark band with three proof figures, the four errors (each with a marked-up snippet), five steps from photo to credit, pricing for all three customers, a section on covering every municipality with the three metro pages, and an FAQ. The search setup, heading outline and structured data are in [landing-page.md](landing-page.md).

## Visual system

| Token          | Value                                  | Use                                                |
| -------------- | -------------------------------------- | -------------------------------------------------- |
| Ink            | `#12161C`                              | Text, sidebar, summary blocks                      |
| Secondary text | `#4A525C`                              | Supporting copy                                    |
| Ground         | `#ECEEF0` web, `#F3F4F6` phone         | Page background                                    |
| Surface        | `#FFFFFF`                              | Cards and the bill                                 |
| Rule           | `#DCE0E5`                              | Borders                                            |
| Flag orange    | `#C2410C`; text `#9A3412` on `#FBE4D6` | Marks on the bill, finding numbers, "Likely error" |
| Action blue    | `#1D4ED8`; text `#1E3A8A` on `#DCE5FB` | Buttons, progress, confirmed savings               |

- Orange means something is wrong or needs attention. Blue means an action or progress. Red and green are not used, so the two states differ in lightness as well as hue.
- **Type:** Archivo for UI and headings (headings at 88–90% width), IBM Plex Mono for money, meter readings, account numbers and timeline dates.
- **Shape:** 6–8 px radius on cards and buttons, 4 px on documents, full pills only for status chips.
- **Touch targets** are at least 44 px everywhere.

## Sample data

Every figure in the mockups is illustrative. The mockups still show the earlier pricing (a R299 managed dispute and a 25% success fee); the app now uses prepaid jobs and monthly fees, set in `config/auditor.php`. Text in square brackets, such as `[Agency name]` and `[code]@bills.[domain]`, needs real values.

## Open questions

- **Reply windows and escalation steps per municipality.** The tracker dates are placeholders until each municipality's rules are researched.
- **Municipal addresses.** The directory's 186 billing emails are unverified and there are no municipal managers' addresses yet. Staff verify them in the reference data.
- **Outage data.** The "billed while the water was off" finding assumes a source of outage notices per suburb. It stays silent where we hold none.
- **Email provider.** Sending uses plain SMTP; inbound replies are built for Mailgun's route forwards, with a shared-secret header for other providers.
