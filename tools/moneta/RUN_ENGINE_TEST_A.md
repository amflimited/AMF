# Moneta Engine Test A Runner

The extractor script is committed at:

`tools/moneta/ca_sco_engine_test_a.py`

Run command for the California SCO $500+ band:

```bash
python3 tools/moneta/ca_sco_engine_test_a.py --bands 04_500_PLUS --out-dir data/ca_sco_engine_test_a --top-limit 5000
```

Purpose:
- Fetch the public California SCO ZIP endpoint.
- Preserve raw source metadata and hashes.
- Extract likely business/entity candidates.
- Exclude national, multinational, public-body, bank, insurer, utility, and internal-team records.
- Produce CSV outputs with durable row-level citation strings.

This stage performs source retrieval and candidate extraction only. It does not enrich, contact, or monetize records.
