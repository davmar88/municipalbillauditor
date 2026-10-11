# Landing page

The mockup is the "Landing page" artboard on the design canvas linked from [design.md](design.md). This file covers what the mockup can't show: the search setup, heading outline, structured data and the pages to build next.

## Goal

The page has one primary action, **Check my bill free**. It appears in the hero, on the household pricing card and in the closing band. Managing agents and businesses get text links ("Talk to us about your portfolio", "Ask for a free audit") so they don't compete with it.

The copy explains the service in the order a customer worries about it:

1. What goes wrong on bills (the four errors, each with a marked-up snippet).
2. Exactly what happens after they send a bill (five steps, from the photo to the credit).
3. What it costs.
4. Whether we cover their metro.
5. Their remaining questions.

## Search setup

| Item | Value |
|---|---|
| URL | `/` |
| Language | `<html lang="en-ZA">` |
| Title (55 characters) | Dispute a wrong municipal bill \| Municipal Bill Auditor |
| Meta description (147 characters) | Wrong municipal bill? Send a photo. We find estimated readings, wrong tariffs and outage charges, then write and chase your dispute. Free to check. |
| Canonical | The page's own URL |
| Open Graph | Same title and description, `og:locale` `en_ZA`, and a 1200 × 630 image of the marked-up bill from the hero |

## Heading outline

There is one H1, and every section opens with an H2 that names what a person would search for.

- **H1** Wrong municipal bill? We find the error and get it fixed.
- **H2** Billing errors are common, and they can be fixed.
- **H2** The four municipal billing errors we check for
  - **H3** Estimated meter readings, Wrong tariff category, Charges during outages, Spikes against your history
- **H2** How we fix a wrong municipal bill
  - **H3** One per step, plus three notes on deadlines, paying the undisputed amount and account credits
- **H2** Free to check. Simple pricing when we do the work.
  - **H3** Households, Landlords and managing agents, Businesses and body corporates
- **H2** Municipal bill disputes in Johannesburg, Tshwane and eThekwini
- **H2** Questions about disputing a municipal bill
  - **H3** One per question
- **H2** Check your bill before the dispute window closes.

## Search terms

These are starting guesses, not researched search volumes. Check them in Google Search Console and Keyword Planner before writing more pages.

| Section | Terms it targets |
|---|---|
| Title, hero | dispute municipal bill, wrong municipal bill, check municipal bill |
| Four errors | estimated meter reading, estimated water reading, wrong tariff on municipal bill, billed during water outage |
| How it works | how to dispute a municipal bill, municipal billing query |
| Metros | City of Johannesburg billing dispute, Tshwane municipal account query, eThekwini billing dispute |
| FAQ | how long to dispute a municipal bill, pay while a bill is in dispute |

## Pages to build next

Most local searches will land on a metro page rather than the home page. Each metro card links to one:

- `/municipal-bill-dispute/johannesburg`
- `/municipal-bill-dispute/tshwane`
- `/municipal-bill-dispute/ethekwini`

Each needs its own content: that metro's dispute process, deadline, where to lodge, escalation route and a sample letter. A copy of the same page with the city name swapped won't rank.

After that come short guides that each answer one question and link to the free check. Examples: "What does E mean on my water reading?", "How to read your Johannesburg municipal bill" and "How to dispute a wrong tariff category".

## Structured data

Add JSON-LD to the page head:

- **Organization:** name, URL and logo.
- **Service:** what the service is, where it covers and who provides it (example below).
- **FAQPage:** built from the six FAQ questions. Google now shows FAQ rich results mainly for well-known government and health sites. Use it as context for search engines, not as a listing feature you can count on.

```json
{
  "@context": "https://schema.org",
  "@type": "Service",
  "serviceType": "Municipal bill checks and disputes",
  "provider": { "@type": "Organization", "name": "Municipal Bill Auditor" },
  "areaServed": [
    { "@type": "City", "name": "Johannesburg" },
    { "@type": "City", "name": "Pretoria" },
    { "@type": "City", "name": "Durban" }
  ],
  "offers": { "@type": "Offer", "price": "0", "priceCurrency": "ZAR", "description": "Free bill check" }
}
```

## Build notes

- **Server-rendered.** Serve the page as static HTML. The hero bill is HTML and CSS, not an image, so the largest thing on screen is text and loads fast.
- **Fonts.** Load Archivo and IBM Plex Mono with `display=swap` and preconnect. Self-hosting a subset is faster still.
- **Crawlable content.** FAQ answers use `<details>`, so the text is in the HTML even when collapsed. Every call to action is a real link.
- **Accessibility.** The hero illustration has `role="img"` and a written description. Headings follow the outline above with no skipped levels.

## Before launch

- **Verify the proof figures.** The three numbers in the dark band come from the README's research notes, which still need checking. Link each `[source]` to the original report, or remove the figure.
- **Replace placeholders:** `[R299]`, `[R50]`, `[25%]`, `[bills address]` and `[Company name]`.
- **List only live metros.** Only show metros whose dispute templates are built. Everyone else gets the waitlist link.
