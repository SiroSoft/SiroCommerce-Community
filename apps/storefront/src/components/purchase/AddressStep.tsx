import type { Address } from "@/purchase/contracts";
import { CHECKOUT_ADDRESS_FIELDS } from "@/purchase/checkout-ux";

/**
 * Move focus to the first invalid field. Address inputs live on the quote step;
 * a checkout-only submit must first travel back there before focusing.
 */
export function focusFirstErrorField(errors: Record<string, string[]>, currentStep: string, goToQuote: () => void) {
  const firstField = Object.keys(errors)[0];
  if (!firstField) return;
  const focusField = () => document.getElementById(firstField)?.focus();
  if (!document.getElementById(firstField) && currentStep === "checkout" && (CHECKOUT_ADDRESS_FIELDS as readonly string[]).includes(firstField)) {
    goToQuote();
    window.setTimeout(focusField, 0);
    return;
  }
  window.setTimeout(focusField, 0);
}

export function AddressFields({ address, labelFor, onChange }: {
  address: Address;
  labelFor: (field: string) => string;
  onChange: (field: keyof Address, value: string) => void;
}) {
  return <>{(Object.keys(address) as (keyof Address)[]).map((field) => (
    <label key={field} htmlFor={field}>{labelFor(field)}<input id={field} value={address[field]} onChange={(event) => onChange(field, event.target.value)} /></label>
  ))}</>;
}
