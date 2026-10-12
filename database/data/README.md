# Reference data

## municipalities.csv

Every municipality in South Africa: 8 metropolitan (category A), 205 local (B) and 44 district (C) municipalities, as demarcated since the 2016 local elections.

| Column | What it holds |
| --- | --- |
| `code` | The Municipal Demarcation Board code, such as `JHB`, `WC024` or `DC12`. Unique. |
| `slug` | URL name. Two local municipalities are both called Emalahleni, so theirs end in their province (`emalahleni-mp`, `emalahleni-ec`). |
| `name`, `short_name` | Full name, and the name people use ("Johannesburg", "Stellenbosch"). |
| `category`, `province`, `district_code`, `seat` | `district_code` is the district a local municipality belongs to. Metros and districts leave it blank. |
| `billing_email`, `billing_email_source` | The address listed for account queries, and where it was read. |
| `escalation_email`, `escalation_email_source` | Empty for now. No public source lists municipal managers' email addresses reliably. |
| `aliases` | Other names a bill may use, separated by `\|`. |

Names, codes, provinces, districts and seats come from Wikipedia's [list of municipalities in South Africa](https://en.wikipedia.org/wiki/List_of_municipalities_in_South_Africa), read on 12 October 2026.

The billing emails come from the "Resident queries: Accounts" line on each municipality's contacts page in the Yes! Media directory at [municipalities.co.za](https://municipalities.co.za), read on 12 October 2026. 186 municipalities have one and 71 have none. Personal free-mail addresses (Gmail and similar) were left out. Many of the remaining addresses belong to named staff, and none has been checked with the municipality, so treat them all as unverified. The app prefers an address a staff member has verified, then the one printed on the customer's own bill, and uses these only after that.
