import type { Quote } from "./contracts";

export type QuantityValidation = { valid: true; value: number } | { valid: false; message: string };

export type ValidationCopy = {
  wholeNumber: string;
  atLeast: string;
  validEmail: string;
  fieldRequired: string;
  promoApplied: string;
  promoFailed: string;
};

const ENGLISH_VALIDATION: ValidationCopy = {
  wholeNumber: "Enter a whole-number quantity.",
  atLeast: "Quantity must be at least {minimum}.",
  validEmail: "Enter a valid email address.",
  fieldRequired: "This field is required.",
  promoApplied: "{code} applied. You save {discount} minor units.",
  promoFailed: "{code} could not be applied.",
};

function fillTemplate(template: string, vars: Record<string, string | number>): string {
  return Object.entries(vars).reduce((text, [key, value]) => text.split(`{${key}}`).join(String(value)), template);
}

export function validateQuantity(value: string | number, minimum = 1, copy: ValidationCopy = ENGLISH_VALIDATION): QuantityValidation {
  const parsed = typeof value === "number" ? value : Number(value.trim());
  if (!Number.isInteger(parsed)) return { valid: false, message: copy.wholeNumber };
  if (parsed < minimum) return { valid: false, message: fillTemplate(copy.atLeast, { minimum }) };
  return { valid: true, value: parsed };
}

export function quoteBreakdown(quote: Quote | undefined, fallback: { subtotal: number | undefined }) {
  return {
    subtotal: quote?.subtotal.amount_minor ?? fallback.subtotal,
    discount: quote?.discount?.amount_minor ?? 0,
    giftDiscount: quote?.gift_discount?.amount_minor ?? 0,
    memberDiscount: quote?.member_discount?.amount_minor ?? 0,
    memberDealDiscount: quote?.member_deal?.discount.amount_minor ?? 0,
    memberDealName: quote?.member_deal?.name,
    shipping: quote?.shipping.amount_minor,
    tax: quote?.tax.amount_minor,
    total: quote?.total.amount_minor,
  };
}

/** Address ids rendered by the quote step; checkout only renders the email field. */
export const CHECKOUT_ADDRESS_FIELDS = ["name", "line1", "city", "country", "postal_code"] as const;

export function validateCheckoutFields(address: Record<string, string>, email: string, copy: ValidationCopy = ENGLISH_VALIDATION) {
  const errors: Record<string, string[]> = {};
  if (!/^\S+@\S+\.\S+$/.test(email.trim())) errors.email = [copy.validEmail];
  for (const field of CHECKOUT_ADDRESS_FIELDS) {
    if (!address[field]?.trim()) errors[field] = [copy.fieldRequired];
  }
  return errors;
}

export function promoFeedback(code: string, discount = 0, copy: ValidationCopy = ENGLISH_VALIDATION) {
  return discount > 0 ? fillTemplate(copy.promoApplied, { code, discount }) : fillTemplate(copy.promoFailed, { code });
}

export const checkoutSteps = ["cart", "quote", "checkout", "order-result"] as const;
