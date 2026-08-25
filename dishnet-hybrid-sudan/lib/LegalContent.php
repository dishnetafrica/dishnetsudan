<?php
/**
 * DishNet Customer Legal Documents
 * v4.12.19 — Terms of Service and Privacy Policy content.
 *
 * Document version is tracked in dnLegalVersion() — bump when wording changes
 * materially. Consent table stores (phone, tos_version, privacy_version,
 * accepted_at, ip) so we can tell who has accepted which version and re-prompt
 * only when the version bumps.
 *
 * Pages are public (no auth) so any customer can review them before signing up.
 * Accessible at ?page=terms and ?page=privacy.
 */

declare(strict_types=1);

/** Bump when wording changes. Stored with every consent record. */
function dnLegalVersion(): array {
    return [
        'tos'     => '1.0',
        'privacy' => '1.0',
        'dated'   => '18 April 2026',
    ];
}

/**
 * Terms of Service — deliberately short and specific to DishNet.
 * Returns an array of sections: [['heading' => ..., 'body' => ...], ...]
 * so the render layer can style them without editing HTML here.
 */
function dnTermsContent(): array {
    return [
        [
            'heading' => 'About these Terms',
            'body'    =>
                "DishNet Africa Ltd. (\"DishNet\", \"we\", \"us\") is a telecommunications company " .
                "registered in South Sudan, providing Starlink, fibre, and LTE internet services to " .
                "customers in Juba and across the region. These Terms of Service govern your use of " .
                "our internet services, customer portal, mobile app, and related tools. By signing in " .
                "to the DishNet app or using any DishNet service, you agree to these Terms.",
        ],
        [
            'heading' => 'Our service',
            'body'    =>
                "We provide internet connectivity on a best-effort basis. Speeds, latency, and " .
                "uptime depend on upstream providers (SpaceX/Starlink, fibre partners, LTE carriers) " .
                "and on conditions we do not fully control — including weather, cable cuts, regulatory " .
                "actions, and power availability. We commit to restoring service as quickly as " .
                "reasonably possible when it is disrupted.",
        ],
        [
            'heading' => 'Billing and payment',
            'body'    =>
                "Service is billed monthly and due on the invoice date shown on each bill. " .
                "If payment is not received within 7 days of the due date, a late fee of 5% of " .
                "the outstanding balance will be added. Continued non-payment may result in " .
                "suspension without further notice. Reconnection after suspension costs USD 25. " .
                "Cheques should be made payable to \"DishNet Africa Limited\". We accept bank " .
                "transfers, mobile money, and cash.",
        ],
        [
            'heading' => 'Acceptable use',
            'body'    =>
                "You agree not to use DishNet service for illegal activity, to send unsolicited " .
                "bulk communication (spam), to attack or probe other networks without authorisation, " .
                "or to host commercial services that violate Starlink's, our fibre partners', or our " .
                "carriers' acceptable use policies. You agree not to resell DishNet connectivity " .
                "without a written commercial agreement with us. We reserve the right to suspend " .
                "service for abuse that threatens the network or other customers.",
        ],
        [
            'heading' => 'Equipment',
            'body'    =>
                "On leased-kit plans, hardware (Starlink dishes, routers, ONTs, LTE terminals) " .
                "remains the property of DishNet throughout the life of the service. You must not " .
                "sell, lend, transfer, or modify the equipment. If equipment is lost, stolen, or " .
                "damaged through misuse, you are responsible for replacement costs. On purchase " .
                "plans, the equipment is yours after full payment.",
        ],
        [
            'heading' => 'Starlink transfers',
            'body'    =>
                "Starlink accounts managed on your behalf by DishNet may be transferred to you " .
                "after a minimum 6-month service period, subject to a USD 150 transfer fee and a " .
                "120-day lead time for administrative processing. Transfer terms are governed by " .
                "SpaceX/Starlink policy and may change without notice.",
        ],
        [
            'heading' => 'Termination',
            'body'    =>
                "You may end your service at any time by contacting DishNet support. We may end " .
                "service for unpaid bills, prohibited use, or repeated violations of these Terms. " .
                "On termination, outstanding balances remain due and leased equipment must be " .
                "returned within 14 days.",
        ],
        [
            'heading' => 'Limitation of liability',
            'body'    =>
                "DishNet is not liable for business loss, lost revenue, or damages resulting from " .
                "service interruption, except where required by law. We will credit your account " .
                "for unplanned extended outages at our discretion. Our total liability for any " .
                "claim related to service is limited to the fees you paid us in the three months " .
                "preceding the event.",
        ],
        [
            'heading' => 'Changes to these Terms',
            'body'    =>
                "We may update these Terms as our services evolve. When we make a material change, " .
                "you will be asked to accept the new Terms the next time you sign in to the app. " .
                "Continuing to use DishNet service after accepting new Terms means you agree to " .
                "the updated version.",
        ],
        [
            'heading' => 'Jurisdiction',
            'body'    =>
                "These Terms are governed by the laws of the Republic of South Sudan. " .
                "Any dispute that cannot be resolved between you and DishNet will be submitted " .
                "to the courts of Juba.",
        ],
        [
            'heading' => 'Contact',
            'body'    =>
                "Questions about these Terms? Reach us on WhatsApp at +211 921 443 002 or email " .
                "info@dishnetafrica.com. We reply fastest on WhatsApp.",
        ],
    ];
}

/**
 * Privacy Policy — what we collect, why, who we share with.
 */
function dnPrivacyContent(): array {
    return [
        [
            'heading' => 'Who this applies to',
            'body'    =>
                "This Privacy Policy explains how DishNet Africa Ltd. handles information about " .
                "you when you use our internet services, customer portal, or mobile app. It applies " .
                "to every DishNet customer and anyone who contacts us about becoming a customer.",
        ],
        [
            'heading' => 'What we collect',
            'body'    =>
                "Account information you give us: your name, phone number, email, physical address, " .
                "and the services you subscribe to. Usage information our systems record automatically: " .
                "how much data your connection uses, which services are active, your invoice and payment " .
                "history, network performance at your site, and WiFi configuration (network name, " .
                "encryption type — but not your password in plain text, that stays on your router). " .
                "Device information shown in the Connected Devices view: the MAC addresses of devices " .
                "currently connected to your WiFi, their IP addresses, and link speed.",
        ],
        [
            'heading' => 'How we use it',
            'body'    =>
                "We use this information to provide your service, bill you correctly, send you " .
                "invoices and receipts on WhatsApp, diagnose technical issues, and comply with " .
                "legal and tax obligations. We do not use your information to target you with " .
                "third-party advertising. We do not sell your information.",
        ],
        [
            'heading' => 'WhatsApp and login codes',
            'body'    =>
                "We sign you into the DishNet app by sending a six-digit code to your WhatsApp " .
                "number. That means your phone number travels through Meta's WhatsApp Business " .
                "infrastructure, subject to Meta's own privacy terms. We never ask for your " .
                "WhatsApp password — only your phone number so we can route the code to you.",
        ],
        [
            'heading' => 'Who we share with',
            'body'    =>
                "Starlink (SpaceX) — for Starlink service customers, we share account and kit " .
                "information required to provision service on their network. Fibre and LTE partners " .
                "(e.g. Splynx-managed operators) — for service activation and support. Payment " .
                "processors and our accountants — for invoicing and tax records. South Sudan " .
                "regulatory authorities — if required by law, such as for tax audits or lawful " .
                "investigation. We do not share your data with advertisers, data brokers, or " .
                "anyone else without your permission.",
        ],
        [
            'heading' => 'How long we keep it',
            'body'    =>
                "For as long as you are a DishNet customer, plus up to 3 additional years after " .
                "you leave, primarily to meet financial record-keeping requirements. Technical " .
                "data (connection logs, device lists) is retained for a shorter period — typically " .
                "90 to 365 days — unless needed for an active support case.",
        ],
        [
            'heading' => 'Your rights',
            'body'    =>
                "You can request a copy of the information we hold about you. You can ask us to " .
                "correct information that is wrong. You can ask us to delete your information when " .
                "you leave DishNet, subject to the financial record-keeping obligation above. " .
                "To exercise any of these rights, contact info@dishnetafrica.com with your " .
                "DishNet account number.",
        ],
        [
            'heading' => 'Security',
            'body'    =>
                "We protect your information with industry-standard safeguards: encrypted " .
                "connections to our portal, hashed authentication tokens, rate-limited login, " .
                "and limited internal access on a need-to-know basis. No system is perfectly " .
                "secure — if a breach ever affects your data, we will notify you without undue " .
                "delay and explain what happened.",
        ],
        [
            'heading' => 'Children',
            'body'    =>
                "DishNet service is intended for adult customers. We do not knowingly collect " .
                "information from anyone under the age of 18. If you believe a minor has signed " .
                "up for service under their own name, please contact us so we can review the account.",
        ],
        [
            'heading' => 'Changes to this Policy',
            'body'    =>
                "We will post any material changes to this Policy on this page and ask you to " .
                "accept the updated version the next time you sign in to the app. The current " .
                "version and date appear at the top of this page.",
        ],
        [
            'heading' => 'Contact',
            'body'    =>
                "Questions about your privacy? WhatsApp +211 921 443 002 or email " .
                "info@dishnetafrica.com — we'll respond within a few business days.",
        ],
    ];
}
