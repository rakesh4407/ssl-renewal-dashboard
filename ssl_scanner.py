#!/usr/bin/env python3
"""
ssl_scanner.py — Standalone SSL Certificate Scanner
(Matches the Google Colab notebook output exactly)

Reads an Excel/CSV file with a "Domain" column, connects to each domain
via TLS, extracts certificate details, and writes enriched results to
an output Excel file.

Usage:
    python ssl_scanner.py <input_file> <output_file>

Outputs JSON progress lines to stdout for the PHP backend to read:
    {"type":"progress","current":5,"total":100,"domain":"example.com"}
    {"type":"complete","output":"path/to/output.xlsx","total":100,"success":95,"failed":5}
    {"type":"error","message":"..."}
"""

import sys
import ssl
import socket
import json
import re
import os
import pandas as pd
from datetime import datetime


def emit(data):
    """Print a JSON line to stdout for the PHP backend."""
    print(json.dumps(data), flush=True)


def get_ssl_info(hostname):
    """Connect to a domain and retrieve its SSL certificate.
    Uses ssl.create_default_context() — same as the Colab notebook.
    """
    port = 443
    if not hostname or pd.isna(hostname):
        return ""
    try:
        hostname = str(hostname).strip()
        # Remove http/https if present
        hostname = hostname.replace('http://', '').replace('https://', '')
        # Remove paths and ports
        hostname = hostname.split('/')[0].split(':')[0]

        context = ssl.create_default_context()

        with socket.create_connection((hostname, port), timeout=10) as sock:
            with context.wrap_socket(sock, server_hostname=hostname) as ssock:
                cert = ssock.getpeercert()
                return cert
    except Exception as e:
        emit({"type": "log", "message": f"Error getting SSL info for {hostname}: {str(e)}"})
        return ""


def extract_issuer_organization(cert):
    if not cert or not isinstance(cert, dict):
        return ""

    issuer = cert.get('issuer', {})

    # Method 1: Standard tuple format
    if isinstance(issuer, tuple):
        for field in issuer:
            for (key, value) in field:
                if key.lower() == 'organizationname':
                    return value

    # Method 2: Dictionary format
    elif isinstance(issuer, dict):
        for key, value in issuer.items():
            if 'organization' in key.lower():
                return value

    # Method 3: String parsing fallback
    issuer_str = str(issuer)
    org_match = re.search(r'O\s*=\s*([^,]+)', issuer_str)
    if org_match:
        return org_match.group(1).strip()

    # Method 4: Subject field fallback
    subject = cert.get('subject', {})
    if isinstance(subject, tuple):
        for field in subject:
            for (key, value) in field:
                if key.lower() == 'organizationname':
                    return value

    return ""


def extract_issuer_common_name(cert):
    """Extract the CA's common name (e.g. 'R3', 'DigiCert').

    Fixed: previously this ran hostname-cleanup regexes (stripping
    'www.'/'ssl.' prefixes, splitting on '.') on what is actually a
    Certificate Authority name, not a domain. That's a different kind of
    string and that cleanup logic didn't belong here. Now we just try to
    match against known CA names directly.
    """
    if not cert or not isinstance(cert, dict):
        return ""

    issuer = cert.get('issuer', {})
    common_name = ""

    # Method 1: Standard tuple format
    if isinstance(issuer, tuple):
        for field in issuer:
            for (key, value) in field:
                if key.lower() == 'commonname':
                    common_name = value
                    break

    # Method 2: Dictionary format
    elif isinstance(issuer, dict):
        for key, value in issuer.items():
            if 'commonname' in key.lower():
                common_name = value
                break

    # Method 3: String parsing fallback
    if not common_name:
        issuer_str = str(issuer)
        cn_match = re.search(r'CN\s*=\s*([^,]+)', issuer_str)
        if cn_match:
            common_name = cn_match.group(1).strip()

    if not common_name:
        return ""

    known_cas = ['Sectigo', 'DigiCert', 'GeoTrust', 'Go Daddy', 'GlobalSign', "Let's Encrypt",
                 'COMODO', 'cPanel', 'R3', 'Cloudflare', 'Amazon', 'Google', 'Microsoft']
    for ca in known_cas:
        if ca.lower() in common_name.lower():
            return ca

    # Otherwise return the CA CN as-is (no domain-style splitting/stripping)
    return common_name.strip()


def extract_wildcard_common_name(cert):
    if not cert or not isinstance(cert, dict):
        return ""

    try:
        subject = cert.get('subject', ())

        if isinstance(subject, tuple):
            for field in subject:
                for (key, value) in field:
                    if key.lower() == 'commonname' and isinstance(value, str) and value.startswith('*.'):
                        return value

        elif isinstance(subject, dict):
            for key, value in subject.items():
                if 'commonname' in key.lower() and isinstance(value, str) and value.startswith('*.'):
                    return value

        return ""
    except Exception:
        return ""


def extract_country(cert):
    if not cert or not isinstance(cert, dict):
        return ""

    subject = cert.get('subject', {})
    country = ""

    if isinstance(subject, tuple):
        for field in subject:
            for (key, value) in field:
                if key.lower() == 'countryname':
                    country = value
                    break

    elif isinstance(subject, dict):
        for key, value in subject.items():
            if 'countryname' in key.lower():
                country = value
                break

    if not country:
        subject_str = str(subject)
        country_match = re.search(r'C\s*=\s*([^,]+)', subject_str)
        if country_match:
            country = country_match.group(1).strip()

    return country


def format_ssl_date(date_str):
    if not date_str:
        return None

    # Clean the date string
    date_str = date_str.replace('GMT', '').strip()
    # FIX: OpenSSL pads single-digit days with an extra space
    # (e.g. "Jan  6 12:00:00 2024"), which broke strptime matching below.
    # Collapse any run of whitespace down to a single space.
    date_str = re.sub(r'\s+', ' ', date_str)

    formats_to_try = [
        "%b %d %H:%M:%S %Y",
        "%Y-%m-%d %H:%M:%S",
        "%d %b %Y %H:%M:%S",
        "%Y%m%d%H%M%SZ",
        "%Y%m%d%H%M%S",
    ]

    for fmt in formats_to_try:
        try:
            return datetime.strptime(date_str, fmt)
        except ValueError:
            continue

    return None


def find_domain_column(df):
    """Flexibly find the domain column regardless of exact name."""
    for col in df.columns:
        if 'domain' in col.strip().lower():
            return col
    return None


def main():
    if len(sys.argv) != 3:
        emit({"type": "error", "message": "Usage: python ssl_scanner.py <input> <output>"})
        sys.exit(1)

    input_file = sys.argv[1]
    output_file = sys.argv[2]

    if not os.path.exists(input_file):
        emit({"type": "error", "message": f"Input file not found: {input_file}"})
        sys.exit(1)

    # Read input file
    try:
        ext = os.path.splitext(input_file)[1].lower()
        if ext == '.csv':
            df = pd.read_csv(input_file)
        else:
            df = pd.read_excel(input_file)
    except Exception as e:
        emit({"type": "error", "message": f"Failed to read input file: {str(e)}"})
        sys.exit(1)

    # Find domain column
    domain_col = find_domain_column(df)
    if not domain_col:
        emit({"type": "error", "message": f"No 'Domain' column found. Columns: {list(df.columns)}"})
        sys.exit(1)

    total = len(df)
    today_date = datetime.now().date()

    emit({"type": "start", "total": total})

    results = []
    success_count = 0
    failed_count = 0

    for idx, domain in enumerate(df[domain_col]):
        try:
            # Emit progress
            domain_str = str(domain) if domain and str(domain) != 'nan' else "(empty)"
            emit({
                "type": "progress",
                "current": idx + 1,
                "total": total,
                "domain": domain_str
            })

            ssl_info = get_ssl_info(domain)

            if isinstance(ssl_info, dict):
                not_before = format_ssl_date(ssl_info.get("notBefore", ""))
                not_after = format_ssl_date(ssl_info.get("notAfter", ""))
                organization_name = extract_issuer_organization(ssl_info)
                issuer_common_name = extract_issuer_common_name(ssl_info)
                wildcard_cn = extract_wildcard_common_name(ssl_info)
                country = extract_country(ssl_info)

                validity_days = None
                validity_years = None
                today_str = today_date.strftime("%d/%m/%Y")
                days_remaining = None

                if not_before and not_after:
                    validity_days = (not_after.date() - not_before.date()).days
                    validity_years = "1 year" if validity_days < 380 else "2 years"
                    days_remaining = (not_after.date() - today_date).days

                not_before_str = not_before.strftime("%d/%m/%Y") if not_before else ""
                not_after_str = not_after.strftime("%d/%m/%Y") if not_after else ""

                success_count += 1
            else:
                not_before_str, not_after_str, organization_name = "", "", ""
                issuer_common_name = ""
                validity_days, validity_years = "", ""
                today_str = today_date.strftime("%d/%m/%Y")
                wildcard_cn = ""
                days_remaining = None
                country = ""
                failed_count += 1

            results.append({
                'Domain': domain_str if domain_str != "(empty)" else "",
                'SSL_Not_Before': not_before_str,
                'SSL_Not_After': not_after_str,
                'Organization_Name': organization_name,
                'Common_Name': issuer_common_name,
                'Validity_days': validity_days,
                'Validity_years': validity_years,
                'Today_date': today_str,
                'Wild_card_Common_Name': wildcard_cn,
                'days_remaining': days_remaining,
                'Country': country,
                'Email': '',
                'Phone Number': '',
            })

        except Exception as e:
            emit({"type": "log", "message": f"Error processing {domain}: {str(e)}"})
            failed_count += 1
            results.append({
                'Domain': str(domain) if domain else "",
                'SSL_Not_Before': "",
                'SSL_Not_After': "",
                'Organization_Name': "",
                'Common_Name': "",
                'Validity_days': "",
                'Validity_years': "",
                'Today_date': today_date.strftime("%d/%m/%Y"),
                'Wild_card_Common_Name': "",
                'days_remaining': None,
                'Country': "",
                'Email': '',
                'Phone Number': '',
            })

    # Write output
    try:
        result_df = pd.DataFrame(results)
        result_df.to_excel(output_file, index=False)
        emit({
            "type": "complete",
            "output": output_file,
            "total": total,
            "success": success_count,
            "failed": failed_count
        })
    except Exception as e:
        emit({"type": "error", "message": f"Failed to write output: {str(e)}"})
        sys.exit(1)


if __name__ == "__main__":
    main()
