# Design: screens and layout

This is the first layout for the app. It covers the service flow, four phone screens for households and three web screens for managing agents and businesses. The mockups are on a design canvas: <https://claude.ai/artifact/EswiWTVaWHW7absDnVB7tC> (private until shared from its Share menu).

## Principles

These come from the risks in the [README](../README.md).

- **Every flag shows its evidence.** False alarms burn trust, so each finding has a confidence label ("Likely error", "Worth checking" or "Needs your answer"), the figures behind it, and a way to say it is not an error.
- **The bill is the interface.** Findings are numbered, and the same numbers are marked in orange on the bill itself.
- **The deadline is always visible.** Every flagged bill and open dispute shows the date and the days left.
- **Ask only what we can't know.** The customer is asked about things like outage dates or a change in occupancy, never to re-type what is on the bill.
- **Templates are per metro.** The dispute letter says which metro's template it uses.
- **POPIA from the first screen.** The upload screen says the bill is used only to check and dispute it, and can be deleted at any time.

## Service flow

One flow serves all three customers. Only who pays changes.

| Step | Who | What happens |
|---|---|---|
| 1 | Customer | Sends the bill by photo, email forward or PDF |
| 2 | Bill Auditor | Reads meter readings, tariff and charges |
| 3 | Bill Auditor | Runs the four checks: estimated readings, wrong tariff, outage charges, spikes against history |
| 4 | Customer | No flags: gets an all-clear and the bill is kept as usage history. Flags: confirms the facts only they know |
| 5 | Bill Auditor | Drafts the dispute from the metro's template and sets the deadline |
| 6 | Customer | Approves the letter, or pays for a managed dispute |
| 7 | Bill Auditor | Lodges it, records the reference number and tracks the reply date |
| 8 | Bill Auditor | No reply in time: escalates to the ward councillor, then the Public Protector |
| 9 | Municipality | Corrects the bill and credits the account |
| 10 | Bill Auditor | Confirms the credit on the next bill, then invoices any success fee |

## Screens

### Households (phone, 390 px wide)

1. **Check a bill.** The main action is photographing the bill, with PDF upload and an email forwarding address as alternatives. Below that are past bills with their status and a short list of the four checks.
2. **Results.** A dark summary block gives the number of likely errors, the rand amount at stake and the dispute deadline. Below it, a compact copy of the bill shows the orange marks. Each finding card has its evidence (for example six months of usage with the estimated month hatched) and one action. A "Checked and fine" list shows what passed.
3. **Dispute letter.** A step indicator, a letter preview in the metro's format, attachments, and a choice between sending it yourself (free) and a managed dispute (R299).
4. **Case tracker.** A countdown to the reply date, the municipal reference number, a timeline from "Bill checked" to "Credit on your account" with escalation steps marked as "only if needed", and a reminder to keep paying the undisputed amount.

Bottom tabs: Bills, Disputes, Property.

### Managing agents (web)

The web app uses a dark sidebar (232 px) and a fluid content area. On a phone the sidebar stacks above the content.

5. **Portfolio.** Four figures for the billing cycle (bills checked, open flags, deadlines in the next 7 days, credits won this year), a units table sorted by urgency with filter chips, a "Due this week" list, and open flags by type.
6. **Bill audit workspace.** The bill is shown as a document on the left with orange marks. The findings panel is on the right, using the same numbers. Each finding shows its evidence and calculation. Findings that might be a false alarm ask a question first ("Did anything change at this unit in September?"). Twelve-month usage charts for water and electricity sit below.

### Businesses and body corporates (web)

7. **Claim.** The back-refund claimed, the monthly saving since the fix and the fee rate, then a dated timeline, a table showing how the fee is calculated, the tariff before and after the fix, and the evidence on file. The fee table makes it clear that nothing is invoiced until money reaches the municipal account.

## Visual system

| Token | Value | Use |
|---|---|---|
| Ink | `#12161C` | Text, sidebar, summary blocks |
| Secondary text | `#4A525C` | Supporting copy |
| Ground | `#ECEEF0` web, `#F3F4F6` phone | Page background |
| Surface | `#FFFFFF` | Cards and the bill |
| Rule | `#DCE0E5` | Borders |
| Flag orange | `#C2410C`; text `#9A3412` on `#FBE4D6` | Marks on the bill, finding numbers, "Likely error" |
| Action blue | `#1D4ED8`; text `#1E3A8A` on `#DCE5FB` | Buttons, progress, confirmed savings |

- Orange means something is wrong or needs attention. Blue means an action or progress. Red and green are not used, so the two states differ in lightness as well as hue.
- **Type:** Archivo for UI and headings (headings at 88–90% width), IBM Plex Mono for money, meter readings, account numbers and timeline dates.
- **Shape:** 6–8 px radius on cards and buttons, 4 px on documents, full pills only for status chips.
- **Touch targets** are at least 44 px everywhere.

## Sample data

Every figure in the mockups is illustrative. The business claim uses the pricing model's example client: a R150,000 monthly bill, a back-refund of 5% of a year's bill (R90,000) and a 25% fee (R22,500). The R299 household fee is the model's Year 1 guess. Text in square brackets, such as `[Agency name]` and `[code]@bills.[domain]`, needs real values.

## Open questions

- **Reply windows and escalation steps per metro.** The tracker dates are placeholders until each metro's rules are researched.
- **Outage data.** The "billed while the water was off" finding assumes a source of outage notices per suburb.
- **Forwarding addresses.** The format and domain for each customer's forwarding address.
- **Managed dispute payment.** Whether households pay when the dispute is lodged or when it is resolved.
