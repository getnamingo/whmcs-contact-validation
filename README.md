# WHMCS Contact Validation
ICANN / NIS2-style registrant contact validation tracking.

## Installation

```bash
git clone https://github.com/getnamingo/whmcs-contact-validation
mv whmcs-contact-validation/namingo_contact_validation /var/www/whmcs/modules/addons
chown -R www-data:www-data /var/www/whmcs/modules/addons/namingo_contact_validation
chmod -R 755 /var/www/whmcs/modules/addons/namingo_contact_validation
```

- Go to **Settings → Apps & Integrations** in the WHMCS admin area, search for **"Contact Validation"**, activate the module, and then configure it from its respective configuration menu.

## Upgrade

Before upgrading, back up the WHMCS database:

```bash
mysqldump -u root -p WHMCS_DATABASE_NAME > /root/whmcs-before-contact-validation-upgrade.sql
```

Upgrade the module:

```bash
cd /tmp
rm -rf /tmp/whmcs-contact-validation
git clone --depth 1 https://github.com/getnamingo/whmcs-contact-validation
rm -rf /var/www/whmcs/modules/addons/namingo_contact_validation
mv whmcs-contact-validation/namingo_contact_validation /var/www/whmcs/modules/addons/namingo_contact_validation
chown -R www-data:www-data /var/www/whmcs/modules/addons/namingo_contact_validation
chmod -R 755 /var/www/whmcs/modules/addons/namingo_contact_validation
rm -rf /tmp/whmcs-contact-validation
```

Finally, log in to the WHMCS admin area. WHMCS will detect the new module version and run the upgrade routine automatically.

## Usage Instructions

This module is a **WHMCS Contact Validation for Namingo Registrar**.  
It is an integral part of the **Namingo Registrar** project and is intended to be used as the WHMCS integration layer for registrar operations.

Detailed, step-by-step usage instructions are provided as part of the Namingo Registrar documentation and project resources.

For the Namingo Registrar core project and overall architecture, see:  
https://github.com/getnamingo/registrar

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/whmcs-contact-validation/issues) section of our GitHub repository.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Support This Project

If you find WHMCS Contact Validation useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

WHMCS Contact Validation is licensed under the MIT License.