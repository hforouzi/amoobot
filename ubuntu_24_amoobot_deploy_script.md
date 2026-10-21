# Ubuntu 24.04 Deployment Script for Amoobot

این سند قبلاً یک کپی از `deploy.sh` قدیمی (با رمزهای ثابت در خود اسکریپت) بود و دیگر معتبر نیست.

- اسکریپت فعلی: [`deploy.sh`](deploy.sh) — بدون هیچ رمزی؛ تنظیمات و رمزها از `/etc/amoobot/.env.prod.local` خوانده می‌شوند.
- قالب تنظیمات: [`.env.prod.example`](.env.prod.example)
- راهنمای کامل (اولین دیپلوی، به‌روزرسانی، Rollback، بکاپ/بازگردانی، چرخش رمزها، چک‌لیست Go-live):
  [`docs/fa/deployment.md`](docs/fa/deployment.md)

```bash
sudo ./deploy.sh --dry-run   # فقط نمایش کارهایی که انجام می‌شود
sudo ./deploy.sh
```
