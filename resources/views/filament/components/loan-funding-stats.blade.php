{{--
    Live funding stats for the loan edit form («Текущо състояние»).
    Three stat cards in the boss's vocabulary (2026-08-10): Сума на
    кредита / Инвестирани / Свободни за инвестиране — recomputed from the
    DB on every page load, никога въвеждани на ръка.

    Inline styles on purpose: the panel's compiled Tailwind does not
    include the app theme's utilities, and inline survives both light and
    dark mode via neutral rgba borders + inherited text color.
--}}
<div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.75rem;">
    <div style="border: 1px solid rgba(128, 128, 128, 0.25); border-radius: 0.75rem; padding: 0.75rem 1rem;">
        <div style="font-size: 0.75rem; opacity: 0.6; margin-bottom: 0.25rem;">Сума на кредита</div>
        <div style="font-size: 1.25rem; font-weight: 700; line-height: 1.2;">{{ $amount }} €</div>
    </div>
    <div style="border: 1px solid rgba(128, 128, 128, 0.25); border-radius: 0.75rem; padding: 0.75rem 1rem;">
        <div style="font-size: 0.75rem; opacity: 0.6; margin-bottom: 0.25rem;">Инвестирани</div>
        <div style="font-size: 1.25rem; font-weight: 700; line-height: 1.2; color: #2563eb;">{{ $invested }} €</div>
    </div>
    <div style="border: 1px solid rgba(22, 163, 74, 0.4); border-radius: 0.75rem; padding: 0.75rem 1rem; background: rgba(22, 163, 74, 0.06);">
        <div style="font-size: 0.75rem; opacity: 0.6; margin-bottom: 0.25rem;">Свободни за инвестиране</div>
        <div style="font-size: 1.25rem; font-weight: 700; line-height: 1.2; color: #16a34a;">{{ $remaining }} €</div>
    </div>
</div>
