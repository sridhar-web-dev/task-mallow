<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merchant Dashboard</title>
    <style>
        :root { --bg: #f6f7f9; --panel: #fff; --text: #1c2430; --muted: #667085; --line: #e4e7ec; --accent: #2f5bea; --risk: #b42318; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #12161c; --panel: #1b212a; --text: #e6e9ee; --muted: #98a2b3; --line: #2c343f; --accent: #7f9cf5; --risk: #f97066; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, sans-serif; }
        main { max-width: 960px; margin: 0 auto; padding: 24px 16px; }
        h1 { font-size: 22px; margin: 0 0 16px; }
        h2 { font-size: 15px; margin: 0 0 12px; }
        form { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        input, button { font: inherit; padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); color: var(--text); }
        input { flex: 1 1 180px; }
        button { background: var(--accent); border-color: var(--accent); color: #fff; cursor: pointer; }
        section { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 16px; margin-bottom: 16px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); }
        th { color: var(--muted); font-weight: 500; font-size: 13px; }
        td.number, th.number { text-align: right; font-variant-numeric: tabular-nums; }
        .figure { font-size: 30px; font-weight: 600; }
        .note, #status { color: var(--muted); font-size: 13px; }
        .drop { color: var(--risk); }
        #status { margin-bottom: 16px; min-height: 20px; }
        #status.error { color: var(--risk); }
        [hidden] { display: none; }
    </style>
</head>
<body>
<main>
    <h1 id="title">Merchant Dashboard</h1>

    <form id="connect">
        <input id="merchant-id" type="number" min="1" placeholder="Merchant ID" required>
        <input id="api-key" type="password" placeholder="API key" autocomplete="off" required>
        <button type="submit">Load</button>
    </form>

    <div id="status" role="status"></div>

    <div id="panels" hidden>
        <section>
            <h2>Projected overage revenue</h2>
            <div class="figure" id="overage"></div>
            <div class="note">Current cycle, from each customer's daily average so far.</div>
        </section>

        <section>
            <h2>Top 5 customers this month</h2>
            <table>
                <thead><tr><th>Customer</th><th>ID</th><th class="number">Units</th></tr></thead>
                <tbody id="top"></tbody>
            </table>
        </section>

        <section>
            <h2>Churn risk <span class="note" id="churn-window"></span></h2>
            <table>
                <thead><tr><th>Customer</th><th>ID</th><th class="number">Last month</th><th class="number">This month</th><th class="number">Drop</th></tr></thead>
                <tbody id="churn"></tbody>
            </table>
        </section>
    </div>
</main>

<script>
    const byId = (id) => document.getElementById(id);
    const number = new Intl.NumberFormat();

    function setStatus(message, isError = false) {
        byId('status').textContent = message;
        byId('status').classList.toggle('error', isError);
    }

    function row(cells) {
        const tr = document.createElement('tr');

        cells.forEach(({ text, numeric, className }) => {
            const td = document.createElement('td');
            td.textContent = text;
            td.className = [numeric ? 'number' : '', className ?? ''].join(' ').trim();
            tr.append(td);
        });

        return tr;
    }

    function emptyRow(columns, text) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = columns;
        td.className = 'note';
        td.textContent = text;
        tr.append(td);

        return tr;
    }

    function render(data) {
        byId('title').textContent = `${data.merchant.name} dashboard`;

        byId('overage').textContent = new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: data.projected_overage.currency,
        }).format(data.projected_overage.amount_minor / 100);

        const top = data.top_customers.customers.map((customer) => row([
            { text: customer.name },
            { text: customer.customer_id },
            { text: number.format(customer.units), numeric: true },
        ]));
        byId('top').replaceChildren(...(top.length ? top : [emptyRow(3, 'No usage this month yet.')]));

        const churn = data.churn_risk;
        byId('churn-window').textContent = churn.current_from
            ? `${churn.current_from} to ${churn.current_to} against ${churn.previous_from} to ${churn.previous_to}, ${churn.total} flagged`
            : 'needs at least one finished day this month';

        const risks = churn.customers.map((customer) => row([
            { text: customer.name },
            { text: customer.customer_id },
            { text: number.format(customer.previous_units), numeric: true },
            { text: number.format(customer.current_units), numeric: true },
            { text: `${customer.drop_percent}%`, numeric: true, className: 'drop' },
        ]));
        byId('churn').replaceChildren(...(risks.length ? risks : [emptyRow(5, 'No customers at risk.')]));

        byId('panels').hidden = false;
        setStatus(`Generated ${new Date(data.generated_at).toLocaleString()}. Usage rolls up hourly.`);
    }

    byId('connect').addEventListener('submit', async (event) => {
        event.preventDefault();
        byId('panels').hidden = true;
        setStatus('Loading…');

        try {
            const response = await fetch(`/api/merchants/${encodeURIComponent(byId('merchant-id').value)}/dashboard`, {
                headers: { Accept: 'application/json', 'X-Api-Key': byId('api-key').value },
            });

            if (!response.ok) {
                setStatus(response.status === 401 ? 'That API key was not recognized.' : `Could not load the dashboard (${response.status}).`, true);
                return;
            }

            render(await response.json());
        } catch {
            setStatus('Could not reach the server.', true);
        }
    });
</script>
</body>
</html>
