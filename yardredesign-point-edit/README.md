# YardRedesign Point Edit

A sanitized portfolio sample based on the Point Edit workflow used in YardRedesign.com, a WordPress-based AI exterior and yard redesign application.

## What Point Edit does

Instead of regenerating an entire property image, Point Edit lets the user click one or more exact locations on the current image and describe a local change for each point.

Example workflow:

1. The user opens an existing yard or exterior image.
2. The user clicks a precise location on the image.
3. The frontend stores normalized X/Y coordinates and displays a numbered marker.
4. The user writes an instruction for that point, such as "add a small wall light here".
5. Multiple point instructions can be sent in a single request.
6. WordPress validates and normalizes the point payload.
7. The backend builds a strict local-edit prompt that identifies each target by coordinates.
8. The image model receives the source image and the point-edit instructions.
9. The returned image is displayed while unrelated areas are intended to remain unchanged.

## Files

- `point-edit-frontend.js` - click/drag marker UI, normalized coordinate calculation and payload creation.
- `point-edit-backend.php` - WordPress AJAX handler, payload validation, local-edit prompt construction and a generic AI adapter boundary.

## Architecture

```text
Image
  -> user click / drag
  -> normalized X/Y coordinates
  -> numbered point + instruction
  -> JSON payload
  -> WordPress AJAX
  -> validation / normalization
  -> strict local-edit prompt
  -> server-side AI image adapter
  -> edited image
```

## Security and portfolio scope

This repository intentionally does **not** contain production credentials or the complete commercial application.

Removed/omitted from the public sample:

- API keys and production credential-loading code
- private/admin email addresses
- OTP values and private login logic
- PayPal/payment implementation
- customer email addresses
- PRO credit/account internals
- private cookies and authentication internals
- production upload/storage paths
- unrelated chat, gallery, landing-page and product code

The AI call is represented by `yard_portfolio_ai_point_edit()`. In production, that adapter is implemented server-side and obtains credentials from protected configuration, never from browser JavaScript.

## Notes

This is a focused portfolio example, not a standalone WordPress plugin. It demonstrates the architecture and implementation pattern behind a production feature while keeping private and commercial code out of the public repository.

## Author

Miodrag Aksic

WordPress & AI Integration Specialist | AI-Assisted Developer
