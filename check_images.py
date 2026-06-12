#!/usr/bin/env python3
import requests
import json

# Fetch API response
response = requests.get('https://asianwokandgrill.in/?action=menu_public_items&source=namastemenu')
data = response.json()
items = data.get('items', [])

print(f"Total items: {len(items)}")
print("=" * 80)

# Find items with missing or fallback images
fallback_items = [item for item in items if 'unsplash' in item.get('img', '').lower()]

print(f"\nItems with FALLBACK/MISSING image URLs ({len(fallback_items)} items):\n")
for item in fallback_items[:15]:
    print(f"  ID: {item.get('id'):3d} | Name: {item.get('name'):40s} | Category: {item.get('cat', 'UNKNOWN'):15s}")

print("\n" + "=" * 80)
print(f"Summary:")
print(f"  Valid images: {len([i for i in items if i.get('img') and 'unsplash' not in i.get('img', '').lower()])}")
print(f"  Fallback/Placeholder images: {len(fallback_items)}")
print(f"\nPossible causes:")
print(f"  1. Image URLs not set in database for these items")
print(f"  2. Image URLs not uploaded to storage.files-vault.com")
print(f"  3. API defaulting to fallback when image field is empty")
