#!/usr/bin/env python3
"""
Moneta Engine Test A — California SCO business-candidate extraction.

Purpose:
- Discover/download California SCO public unclaimed-property ZIP files.
- Preserve raw source metadata and hashes.
- Stream records from CSV inside ZIP.
- Extract likely business/entity claim records.
- Exclude large/national/institutional/government/internal-team owners.
- Produce durable row-level citations for later stages.

No outreach, enrichment, or monetization is performed here.
"""

from __future__ import annotations

import argparse
import csv
import datetime as dt
import gzip
import hashlib
import io
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from collections import Counter, defaultdict
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Tuple

OFFICIAL_PAGE = "https://www.sco.ca.gov/upd_download_property_records.html"
DEFAULT_DIRECT_URLS = {
    "00_ALL": "https://claimit.ca.gov/upd-property-records/00_All_Records.zip",
    "01_0_TO_10": "https://claimit.ca.gov/upd-property-records/01_From_0_To_Below_10.zip",
    "02_10_TO_100": "https://claimit.ca.gov/upd-property-records/02_From_10_To_Below_100.zip",
    "03_100_TO_500": "https://claimit.ca.gov/upd-property-records/03_From_100_To_Below_500.zip",
    "04_500_PLUS": "https://claimit.ca.gov/upd-property-records/04_From_500_To_Beyond.zip",
}
BAND_LABELS = {
    "00_ALL": "all_properties",
    "01_0_TO_10": "$0.00_to_$9.99",
    "02_10_TO_100": "$10.00_to_$99.99",
    "03_100_TO_500": "$100.00_to_$499.99",
    "04_500_PLUS": "$500_and_up",
}

ENTITY_INDICATORS = [
    " LLC", " L.L.C", " INC", " INCORPORATED", " CORP", " CORPORATION", " CO ", " COMPANY",
    " LTD", " LIMITED", " LLP", " L.L.P", " LP", " L.P", " PLLC", " P.L.L.C", " PC", " P.C",
    " DBA ", " ASSOC", " ASSOCIATES", " GROUP", " PARTNERS", " PARTNERSHIP", " HOLDINGS",
    " ENTERPRISE", " ENTERPRISES", " SERVICES", " SOLUTIONS", " SYSTEMS", " MANAGEMENT",
    " CONSULTING", " CONSTRUCTION", " CONTRACTORS", " ELECTRIC", " PLUMBING", " ROOFING",
    " LANDSCAPE", " LANDSCAPING", " CLEANING", " RESTAURANT", " CAFE", " DENTAL", " MEDICAL",
    " CLINIC", " AUTO", " MOTORS", " REPAIR", " SALON", " MARKET", " SUPPLY", " DISTRIBUTING",
    " DISTRIBUTORS", " MANUFACTURING", " FARMS", " FARMWORK", " TRUCKING", " LOGISTICS",
    " REALTY", " PROPERTIES", " PROPERTY", " INVESTMENTS", " CAPITAL", " DESIGN", " STUDIO",
    " AGENCY", " INSURANCE", " LAW OFFICES", " ATTORNEY", " ACCOUNTING", " TAX", " FITNESS",
    " CHURCH", " MINISTRIES", " FOUNDATION", " NONPROFIT", " SCHOOL", " ACADEMY"
]

# Hard/soft exclusions: entities likely to have internal finance/legal/admin teams or no realistic payer fit.
EXCLUSION_PATTERNS = {
    "government_or_public_body": [
        r"\bSTATE OF\b", r"\bCOUNTY OF\b", r"\bCITY OF\b", r"\bTOWN OF\b", r"\bVILLAGE OF\b",
        r"\bDEPT\b", r"\bDEPARTMENT\b", r"\bAGENCY\b", r"\bAUTHORITY\b", r"\bDISTRICT\b",
        r"\bSCHOOL DISTRICT\b", r"\bWATER DISTRICT\b", r"\bTRANSIT\b", r"\bMUNICIPAL\b",
        r"\bSUPERIOR COURT\b", r"\bPUBLIC\b", r"\bUNIVERSITY OF CALIFORNIA\b", r"\bCALIFORNIA STATE UNIVERSITY\b"
    ],
    "large_bank_or_financial": [
        r"\bBANK OF AMERICA\b", r"\bWELLS FARGO\b", r"\bJPMORGAN\b", r"\bJP MORGAN\b", r"\bCHASE BANK\b",
        r"\bCITIBANK\b", r"\bCITI BANK\b", r"\bUS BANK\b", r"\bU S BANK\b", r"\bPNC BANK\b",
        r"\bCAPITAL ONE\b", r"\bAMERICAN EXPRESS\b", r"\bDISCOVER\b", r"\bGOLDMAN SACHS\b",
        r"\bMORGAN STANLEY\b", r"\bCHARLES SCHWAB\b", r"\bFIDELITY\b", r"\bVANGUARD\b",
        r"\bBANK\b.*\bN A\b", r"\bNATIONAL ASSOCIATION\b", r"\bFEDERAL CREDIT UNION\b"
    ],
    "large_insurance_healthcare": [
        r"\bKAISER\b", r"\bBLUE CROSS\b", r"\bBLUE SHIELD\b", r"\bUNITEDHEALTH\b", r"\bUNITED HEALTH\b",
        r"\bAETNA\b", r"\bCIGNA\b", r"\bHUMANA\b", r"\bANTHEM\b", r"\bMETLIFE\b",
        r"\bPRUDENTIAL\b", r"\bSTATE FARM\b", r"\bALLSTATE\b", r"\bGEICO\b", r"\bFARMERS INSURANCE\b"
    ],
    "large_national_or_multinational": [
        r"\bAMAZON\b", r"\bAPPLE\b", r"\bMICROSOFT\b", r"\bGOOGLE\b", r"\bALPHABET\b", r"\bMETA\b",
        r"\bFACEBOOK\b", r"\bWALMART\b", r"\bTARGET\b", r"\bCOSTCO\b", r"\bHOME DEPOT\b", r"\bLOWE'?S\b",
        r"\bSTARBUCKS\b", r"\bMCDONALD", r"\bBURGER KING\b", r"\bTACO BELL\b", r"\bYUM BRANDS\b",
        r"\bSHELL\b", r"\bCHEVRON\b", r"\bEXXON\b", r"\bBP\b", r"\bTOYOTA\b", r"\bHONDA\b",
        r"\bFORD MOTOR\b", r"\bGENERAL MOTORS\b", r"\bTESLA\b", r"\bAT&T\b", r"\bVERIZON\b",
        r"\bCOMCAST\b", r"\bT-MOBILE\b", r"\bSPRINT\b", r"\bDISNEY\b", r"\bNETFLIX\b",
        r"\bUPS\b", r"\bFEDEX\b", r"\bDHL\b", r"\bIBM\b", r"\bORACLE\b", r"\bSALESFORCE\b"
    ],
    "large_utility_or_telecom": [
        r"\bPG&E\b", r"\bPACIFIC GAS\b", r"\bSOUTHERN CALIFORNIA EDISON\b", r"\bSOCAL EDISON\b",
        r"\bSDG&E\b", r"\bSAN DIEGO GAS\b", r"\bUTILITY\b", r"\bTELECOM\b"
    ],
    "nonprofit_or_institution_review": [
        r"\bFOUNDATION\b", r"\bUNIVERSITY\b", r"\bCOLLEGE\b", r"\bHOSPITAL\b", r"\bHEALTH SYSTEM\b",
        r"\bMEDICAL CENTER\b", r"\bASSOCIATION\b", r"\bUNION\b", r"\bLOCAL \d+\b"
    ]
}

NAME_COLUMNS = [
    "owner name", "owner_name", "owner", "name", "reported owner name", "property owner", "property owner name",
    "primary owner name", "owner name 1", "ownername", "claimant name"
]
AMOUNT_COLUMNS = [
    "amount", "cash reported", "cash_reported", "reported amount", "property amount", "value", "property value",
    "balance", "amount reported", "amount_due"
]
ID_COLUMNS = [
    "property id", "property_id", "propertyid", "property number", "property_number", "record id", "id", "claim id"
]
ADDRESS_COLUMNS = ["address", "owner address", "street address", "address line 1", "city", "state", "zip", "postal"]


def now_iso() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")


def ensure_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)


def normalize_name(name: str) -> str:
    s = (name or "").upper()
    s = s.replace("&AMP;", "&")
    s = re.sub(r"[^A-Z0-9&.,' -]+", " ", s)
    s = re.sub(r"\s+", " ", s).strip()
    return s


def normalize_key(name: str) -> str:
    s = normalize_name(name)
    s = re.sub(r"\b(THE|A|AN)\b", " ", s)
    s = re.sub(r"[^A-Z0-9]+", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8", errors="replace")).hexdigest()


def fetch_url(url: str, timeout: int = 120, retries: int = 3) -> bytes:
    headers = {
        "User-Agent": "Mozilla/5.0 MonetaEngineTestA/1.0 (+public-source-retrieval)",
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.9",
        "Connection": "close",
    }
    last_err = None
    for attempt in range(1, retries + 1):
        try:
            req = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                return resp.read()
        except Exception as e:
            last_err = e
            if attempt < retries:
                time.sleep(2 * attempt)
    raise RuntimeError(f"Failed to fetch {url}: {last_err!r}")


def download_file(url: str, dest: Path, timeout: int = 300, retries: int = 3) -> Tuple[int, str]:
    headers = {
        "User-Agent": "Mozilla/5.0 MonetaEngineTestA/1.0 (+public-source-retrieval)",
        "Accept": "application/zip,application/octet-stream,*/*",
        "Accept-Language": "en-US,en;q=0.9",
        "Referer": OFFICIAL_PAGE,
        "Connection": "close",
    }
    last_err = None
    for attempt in range(1, retries + 1):
        try:
            req = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(req, timeout=timeout) as resp, dest.open("wb") as out:
                total = 0
                while True:
                    chunk = resp.read(1024 * 1024)
                    if not chunk:
                        break
                    out.write(chunk)
                    total += len(chunk)
                return total, sha256_file(dest)
        except Exception as e:
            last_err = e
            if dest.exists():
                try:
                    dest.unlink()
                except Exception:
                    pass
            if attempt < retries:
                time.sleep(3 * attempt)
    raise RuntimeError(f"Failed to download {url}: {last_err!r}")


def discover_links() -> Dict[str, str]:
    try:
        html = fetch_url(OFFICIAL_PAGE).decode("utf-8", errors="replace")
    except Exception:
        return DEFAULT_DIRECT_URLS.copy()
    urls = DEFAULT_DIRECT_URLS.copy()
    for href in re.findall(r'href=["\']([^"\']+\.zip)["\']', html, flags=re.I):
        full = urllib.parse.urljoin(OFFICIAL_PAGE, href)
        filename = full.rsplit("/", 1)[-1]
        for key, default_url in DEFAULT_DIRECT_URLS.items():
            if filename == default_url.rsplit("/", 1)[-1]:
                urls[key] = full
    return urls


def pick_col(headers: List[str], candidates: List[str]) -> Optional[str]:
    norm_to_actual = {re.sub(r"[^a-z0-9]+", " ", h.lower()).strip(): h for h in headers}
    compact_to_actual = {re.sub(r"[^a-z0-9]+", "", h.lower()): h for h in headers}
    for cand in candidates:
        n = re.sub(r"[^a-z0-9]+", " ", cand.lower()).strip()
        c = re.sub(r"[^a-z0-9]+", "", cand.lower())
        if n in norm_to_actual:
            return norm_to_actual[n]
        if c in compact_to_actual:
            return compact_to_actual[c]
    # Fuzzy contains, conservative
    for h in headers:
        hn = re.sub(r"[^a-z0-9]+", " ", h.lower()).strip()
        for cand in candidates:
            cn = re.sub(r"[^a-z0-9]+", " ", cand.lower()).strip()
            if cn and cn in hn:
                return h
    return None


def detect_columns(headers: List[str]) -> Dict[str, Optional[str]]:
    amount = pick_col(headers, AMOUNT_COLUMNS)
    prop_id = pick_col(headers, ID_COLUMNS)
    name = pick_col(headers, NAME_COLUMNS)
    address_cols = [h for h in headers if any(token in re.sub(r"[^a-z0-9]+", " ", h.lower()) for token in ADDRESS_COLUMNS)]
    return {
        "owner_name_col": name,
        "amount_col": amount,
        "property_id_col": prop_id,
        "address_cols": address_cols[:12],
    }


def likely_business(name_norm: str) -> Tuple[bool, List[str]]:
    reasons = []
    padded = f" {name_norm} "
    for ind in ENTITY_INDICATORS:
        if ind in padded:
            reasons.append(f"entity_indicator:{ind.strip()}")
    if re.search(r"\b[A-Z]+\s+(CONSTRUCTION|PLUMBING|ROOFING|LANDSCAPING|CLEANING|DENTAL|MEDICAL|AUTO|REPAIR|RESTAURANT|SERVICES)\b", name_norm):
        reasons.append("business_trade_phrase")
    # Avoid overly broad catch: uppercase without comma and 3+ tokens may be a business, but route to review unless positive signal.
    if not reasons and "," not in name_norm and len(name_norm.split()) >= 3 and not re.search(r"\b(TRUST|ESTATE|CUSTODIAN|MINOR)\b", name_norm):
        # This is weak; keep as review-like candidate only if it has business words.
        if re.search(r"\b(SERVICE|MARKET|SHOP|STORE|REPAIR|CARE|CENTER|CLUB|GROUP|TEAM|STUDIO|DESIGN)\b", name_norm):
            reasons.append("weak_business_phrase")
    return bool(reasons), reasons


def classify_exclusion(name_norm: str) -> Optional[str]:
    for category, patterns in EXCLUSION_PATTERNS.items():
        for pat in patterns:
            if re.search(pat, name_norm):
                return category
    return None


def amount_numeric(value: str) -> Optional[float]:
    if value is None:
        return None
    s = str(value).replace("$", "").replace(",", "").strip()
    if not s:
        return None
    try:
        return float(s)
    except ValueError:
        return None


def open_csv_from_zip(zip_path: Path) -> Tuple[str, bytes]:
    with zipfile.ZipFile(zip_path) as zf:
        names = [n for n in zf.namelist() if n.lower().endswith(".csv")]
        if not names:
            raise RuntimeError(f"No CSV files found inside {zip_path}")
        # Prefer largest CSV if multiple.
        names.sort(key=lambda n: zf.getinfo(n).file_size, reverse=True)
        inner = names[0]
        return inner, zf.read(inner)


def iter_csv_dicts(csv_bytes: bytes) -> Tuple[List[str], Iterable[Dict[str, str]]]:
    # Try UTF-8 first, then cp1252.
    for enc in ("utf-8-sig", "cp1252", "latin-1"):
        try:
            text = csv_bytes.decode(enc)
            sniffer_sample = text[:8192]
            dialect = csv.Sniffer().sniff(sniffer_sample)
            reader = csv.DictReader(io.StringIO(text), dialect=dialect)
            headers = reader.fieldnames or []
            return headers, reader
        except Exception:
            continue
    text = csv_bytes.decode("utf-8", errors="replace")
    reader = csv.DictReader(io.StringIO(text))
    return reader.fieldnames or [], reader


def write_csv(path: Path, rows: Iterable[Dict[str, object]], fieldnames: List[str], gzip_output: bool = False) -> int:
    count = 0
    if gzip_output:
        f = gzip.open(path, "wt", newline="", encoding="utf-8")
    else:
        f = path.open("w", newline="", encoding="utf-8")
    with f:
        writer = csv.DictWriter(f, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow(row)
            count += 1
    return count


def process_band(band_key: str, url: str, out_dir: Path, top_limit: int) -> Dict[str, object]:
    raw_dir = out_dir / "raw"
    output_dir = out_dir / "outputs"
    ensure_dir(raw_dir)
    ensure_dir(output_dir)
    retrieved_at = now_iso()
    zip_name = url.rsplit("/", 1)[-1]
    zip_path = raw_dir / zip_name
    size_bytes, zip_hash = download_file(url, zip_path)
    inner_csv_name, csv_bytes = open_csv_from_zip(zip_path)
    headers, reader = iter_csv_dicts(csv_bytes)
    colmap = detect_columns(headers)
    owner_col = colmap["owner_name_col"]
    amount_col = colmap["amount_col"]
    prop_col = colmap["property_id_col"]
    if not owner_col:
        raise RuntimeError(f"Could not detect owner/name column. Headers: {headers[:50]}")

    header_profile_path = output_dir / f"{band_key}_header_profile.csv"
    write_csv(header_profile_path, [
        {"header_index": i + 1, "header_name": h, "detected_role": next((k for k, v in colmap.items() if v == h), "")}
        for i, h in enumerate(headers)
    ], ["header_index", "header_name", "detected_role"])

    candidate_fields = [
        "business_name", "amount_value", "amount_band", "property_id", "source_file_url", "official_source_page",
        "source_file_band", "inner_csv_name", "retrieved_at", "raw_row_number", "raw_row_hash", "candidate_status",
        "status_reason", "business_signal", "citation_string"
    ]
    exclusion_fields = candidate_fields + ["exclusion_category"]
    all_candidates_path = output_dir / f"{band_key}_business_candidates.csv.gz"
    top_candidates_path = output_dir / f"{band_key}_business_candidates_top_{top_limit}.csv"
    exclusions_path = output_dir / f"{band_key}_excluded_or_review.csv.gz"

    candidates: List[Dict[str, object]] = []
    exclusions: List[Dict[str, object]] = []
    counts = Counter()
    amount_sum_candidates = 0.0
    amount_known_candidates = 0

    for raw_row_number, row in enumerate(reader, start=2):  # header is row 1
        counts["total_rows"] += 1
        raw_owner = row.get(owner_col, "")
        name_norm = normalize_name(raw_owner)
        if not name_norm:
            counts["missing_owner_name"] += 1
            continue
        is_business, signals = likely_business(name_norm)
        if not is_business:
            counts["not_business_signal"] += 1
            continue
        excl = classify_exclusion(name_norm)
        amt_value = amount_numeric(row.get(amount_col, "")) if amount_col else None
        amount_band = BAND_LABELS.get(band_key, band_key)
        prop_id = row.get(prop_col, "") if prop_col else ""
        raw_basis = json.dumps(row, sort_keys=True, ensure_ascii=False)
        row_hash = sha256_text(raw_basis)
        citation = (
            f"California SCO public unclaimed-property CSV; official_page={OFFICIAL_PAGE}; "
            f"source_file_url={url}; band={amount_band}; inner_csv={inner_csv_name}; "
            f"retrieved_at={retrieved_at}; raw_row_number={raw_row_number}; raw_row_hash={row_hash}"
        )
        base = {
            "business_name": name_norm,
            "amount_value": amt_value if amt_value is not None else "",
            "amount_band": amount_band,
            "property_id": prop_id,
            "source_file_url": url,
            "official_source_page": OFFICIAL_PAGE,
            "source_file_band": amount_band,
            "inner_csv_name": inner_csv_name,
            "retrieved_at": retrieved_at,
            "raw_row_number": raw_row_number,
            "raw_row_hash": row_hash,
            "business_signal": ";".join(signals),
            "citation_string": citation,
        }
        if excl:
            counts[f"excluded_{excl}"] += 1
            exclusions.append({**base, "candidate_status": "excluded_or_review", "status_reason": excl, "exclusion_category": excl})
        else:
            counts["candidate_business"] += 1
            if amt_value is not None:
                amount_sum_candidates += amt_value
                amount_known_candidates += 1
            candidates.append({**base, "candidate_status": "candidate_business", "status_reason": "passed_initial_business_and_exclusion_filter"})

    # Sort known-value candidates descending; blank amounts remain after knowns.
    candidates.sort(key=lambda r: float(r["amount_value"]) if r.get("amount_value") not in (None, "") else -1, reverse=True)
    exclusions.sort(key=lambda r: float(r["amount_value"]) if r.get("amount_value") not in (None, "") else -1, reverse=True)

    write_csv(all_candidates_path, candidates, candidate_fields, gzip_output=True)
    write_csv(top_candidates_path, candidates[:top_limit], candidate_fields)
    write_csv(exclusions_path, exclusions, exclusion_fields, gzip_output=True)

    source_manifest = {
        "band_key": band_key,
        "band_label": BAND_LABELS.get(band_key, band_key),
        "official_source_page": OFFICIAL_PAGE,
        "source_file_url": url,
        "retrieved_at": retrieved_at,
        "raw_zip_path": str(zip_path),
        "raw_zip_name": zip_name,
        "raw_zip_size_bytes": size_bytes,
        "raw_zip_sha256": zip_hash,
        "inner_csv_name": inner_csv_name,
        "headers": headers,
        "detected_columns": colmap,
        "counts": dict(counts),
        "candidate_amount_sum_known": round(amount_sum_candidates, 2),
        "candidate_amount_known_count": amount_known_candidates,
        "outputs": {
            "all_candidates_gzip": str(all_candidates_path),
            "top_candidates_csv": str(top_candidates_path),
            "excluded_or_review_gzip": str(exclusions_path),
            "header_profile_csv": str(header_profile_path),
        }
    }
    (output_dir / f"{band_key}_source_manifest.json").write_text(json.dumps(source_manifest, indent=2), encoding="utf-8")
    return source_manifest


def main(argv: Optional[List[str]] = None) -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--bands", default="04_500_PLUS", help="Comma list of band keys or ALL_CONFIGURED")
    p.add_argument("--out-dir", default="data/ca_sco_engine_test_a")
    p.add_argument("--top-limit", type=int, default=5000)
    args = p.parse_args(argv)

    out_dir = Path(args.out_dir)
    ensure_dir(out_dir / "outputs")
    links = discover_links()
    if args.bands == "ALL_CONFIGURED":
        bands = ["04_500_PLUS", "03_100_TO_500", "02_10_TO_100", "01_0_TO_10"]
    else:
        bands = [b.strip() for b in args.bands.split(",") if b.strip()]

    run = {
        "engine": "Moneta Engine Test A",
        "scope": "California SCO source acquisition/business-candidate extraction only",
        "started_at": now_iso(),
        "official_source_page": OFFICIAL_PAGE,
        "bands_requested": bands,
        "discovered_links": links,
        "band_results": [],
        "blockers": [],
    }
    for band in bands:
        try:
            if band not in links:
                raise RuntimeError(f"Unknown band key {band}. Available: {sorted(links)}")
            result = process_band(band, links[band], out_dir, args.top_limit)
            run["band_results"].append(result)
        except Exception as e:
            run["blockers"].append({"band": band, "error": repr(e), "occurred_at": now_iso()})
    run["finished_at"] = now_iso()
    run_path = out_dir / "outputs" / "run_summary.json"
    run_path.write_text(json.dumps(run, indent=2), encoding="utf-8")
    print(json.dumps(run, indent=2))
    return 1 if run["blockers"] and not run["band_results"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
