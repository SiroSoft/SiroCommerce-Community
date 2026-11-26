"use client";

import { Component } from "react";
import type { ElementType, ReactNode } from "react";
import { useSlot } from "./SlotProvider";
import type {
  SlotErrorFallback,
  SlotErrorRenderer,
  SlotName,
  SlotProps,
  SlotRegistration,
} from "./types";

interface SlotErrorBoundaryProps {
  children: ReactNode;
  fallback?: ReactNode;
}

interface SlotErrorBoundaryState {
  failed: boolean;
}

class SlotErrorBoundary extends Component<SlotErrorBoundaryProps, SlotErrorBoundaryState> {
  state: SlotErrorBoundaryState = { failed: false };

  static getDerivedStateFromError(): SlotErrorBoundaryState {
    return { failed: true };
  }

  render(): ReactNode {
    return this.state.failed ? this.props.fallback ?? null : this.props.children;
  }
}

export interface SlotViewProps extends SlotProps {
  name: SlotName;
  fallback?: ReactNode;
  errorFallback?: SlotErrorRenderer;
  as?: ElementType;
  className?: string;
  wrapperProps?: Record<string, unknown>;
}

function resolveErrorFallback(
  errorFallback: SlotErrorRenderer | undefined,
  registration: SlotRegistration,
  slot: SlotName,
): ReactNode {
  if (typeof errorFallback === "function") {
    return errorFallback({ registration, slot } satisfies SlotErrorFallback);
  }
  return errorFallback ?? null;
}

export function Slot({
  name,
  scope,
  locale,
  data,
  fallback = null,
  errorFallback,
  as: Wrapper,
  className,
  wrapperProps,
}: SlotViewProps) {
  const registrations = useSlot(name);

  const content = registrations.length > 0
    ? registrations.map((registration) => {
        const Component = registration.component;
        return (
          <SlotErrorBoundary
            key={registration.id}
            fallback={resolveErrorFallback(errorFallback, registration, name)}
          >
            <Component scope={scope} locale={locale} data={data} />
          </SlotErrorBoundary>
        );
      })
    : fallback;

  if (content === null || content === undefined || content === false) return null;

  if (!Wrapper) return <>{content}</>;

  return (
    <Wrapper data-slot={name} className={className} {...wrapperProps}>
      {content}
    </Wrapper>
  );
}
