import { Controller } from '@hotwired/stimulus';

/*
 * Beginner questionnaire controller.
 *
 * Renders a simple multi-step questionnaire: each step shows a question and a
 * set of answer options (the number of options varies per step). The user picks
 * an option (which highlights it) and then presses "Next" to continue;
 * "Back" returns to the prior question. Once the last question is answered
 * and confirmed, the server-rendered results section is revealed.
 *
 * Attach with data-controller="beginner-questionnaire" and target elements
 * marked data-beginner-questionnaire-target="stage" and "results".
 */
export default class extends Controller {
    static targets = ['stage', 'results', 'summary'];

    // Dummy questions — swap out for real content later.
    // Each step has a short `label` (for the breadcrumb) and answer options.
    // `shorts` (optional) provides condensed answer text for the breadcrumb;
    // when missing, the full option text is used.
    steps = [
        {
            label: 'Who',
            question: 'Who will be playing the guitar?',
            options: ['A child', 'A teenager', 'An adult', 'I’m not sure'],
            shorts: ['A child', 'A teenager', 'An adult', 'Not sure'],
        },
        {
            label: 'Age',
            question: 'How old is the learner?',
            options: ['Under 6', '6–8', '9–11', '12–15', '16+', 'I’m not sure'],
            shorts: ['Under 6', '6–8', '9–11', '12–15', '16+', 'Not sure'],
        },
        {
            label: 'Music',
            question: 'What kind of music do they want to play?',
            options: [
                'Pop, singer-songwriter, or general songs',
                'Rock, metal, or punk',
                'Classical music',
                'Spanish / flamenco-style music',
                'Folk, country, or campfire songs',
                'They just want to try guitar',
                'I’m not sure',
            ],
            shorts: [
                'Pop & singer-songwriter',
                'Rock & metal',
                'Classical',
                'Spanish / flamenco',
                'Folk & country',
                'Just trying it out',
                'Not sure',
            ],
        },
        {
            label: 'Budget',
            question: 'What budget range feels comfortable?',
            options: ['Under €100', '€100–€200', '€200–€350', '€350+', 'I’m flexible', 'I’m not sure'],
            shorts: ['Under €100', '€100–€200', '€200–€350', '€350+', 'Flexible', 'Not sure'],
        },
        {
            label: 'Reason',
            question: 'What’s the reason for buying the guitar?',
            options: [
                'Starting lessons (or a teacher recommended it)',
                'Learning the basics',
                'Playing favourite songs or general music',
                'Singing and playing at home',
                // 'Playing rock, metal, or electric guitar sounds',
                'It’s a birthday or holiday gift',
                // 'They asked for a guitar',
                'Trying a new hobby without spending too much',
                'I’m not sure yet',
            ],
            shorts: [
                'Starting lessons',
                'Learning basics',
                'Playing songs',
                'Singing at home',
                'Rock & electric',
                'A gift',
                'They asked for it',
                'New hobby',
                'Not sure',
            ],
        },
        {
            label: 'Comfort',
            question: 'Anything we should consider for comfort or fit?',
            options: [
                'No, nothing specific',
                'They may need a smaller or lighter guitar',
                'They have small hands',
                'They have hand, wrist, or joint discomfort',
                'They need a left-handed guitar',
                'I’m not sure',
            ],
            shorts: [
                'Nothing specific',
                'Smaller / lighter',
                'Small hands',
                'Hand / wrist comfort',
                'Left-handed',
                'Not sure',
            ],
        },
    ];

    connect() {
        this.current = 0;
        // Selected option index per step (null = not answered yet).
        this.answers = this.steps.map(() => null);
        this.render();
    }

    // Highlight the chosen option (does not advance).
    choose(event) {
        this.answers[this.current] = Number(event.currentTarget.dataset.index);
        this.render();
    }

    next() {
        // Guard: only advance when the current step has an answer.
        if (this.answers[this.current] === null) return;
        this.current += 1;
        this.render();
    }

    previous() {
        if (this.current === 0) return;
        this.current -= 1;
        this.render();
    }

    restart() {
        this.current = 0;
        this.answers = this.steps.map(() => null);
        this.render();
    }

    // Builds the answer chips for steps [0, upTo). Returns the inner HTML only.
    crumbChips(upTo) {
        const chips = [];
        for (let i = 0; i < upTo; i += 1) {
            const answer = this.answers[i];
            if (answer === null) continue;
            const step = this.steps[i];
            const short = (step.shorts && step.shorts[answer]) || step.options[answer];
            chips.push(`
                <span class="tm-quiz__crumb">
                    ${this.escape(step.label)}: ${this.escape(short)}
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </span>`);
        }
        return chips.join('');
    }

    // Chips summarizing answers already given before the current step.
    breadcrumbHtml() {
        const chips = this.crumbChips(this.current);
        return chips ? `<div class="tm-quiz__crumbs">${chips}</div>` : '';
    }

    escape(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    render() {
        if (this.current >= this.steps.length) {
            this.showResults();
            return;
        }

        // Show the question stage, hide the results section.
        this.stageTarget.hidden = false;
        if (this.hasResultsTarget) {
            this.resultsTarget.hidden = true;
        }

        const step = this.steps[this.current];
        const total = this.steps.length;
        const stepNo = this.current + 1;
        const progress = Math.round((stepNo - 1) / total * 100);
        const selected = this.answers[this.current];
        const isFirst = this.current === 0;
        const isLast = this.current === this.steps.length - 1;
        const canAdvance = selected !== null;

        this.stageTarget.innerHTML = `
            <div class="tm-quiz">
                <div class="tm-quiz__progress" aria-hidden="true">
                    <span class="tm-quiz__progress-bar" style="width: ${progress}%"></span>
                </div>
                <p class="tm-quiz__step">Step ${stepNo} of ${total}</p>
                ${this.breadcrumbHtml()}
                <h2 class="tm-quiz__question">${step.question}</h2>
                <div class="tm-quiz__options">
                    ${step.options
                        .map(
                            (opt, i) => `
                        <button type="button"
                                class="tm-quiz__option${i === selected ? ' tm-quiz__option--selected' : ''}"
                                data-index="${i}"
                                data-action="beginner-questionnaire#choose">
                            ${opt}
                        </button>`
                        )
                        .join('')}
                </div>
                <div class="tm-quiz__nav">
                    <button type="button" class="tm-quiz__nav-btn tm-quiz__nav-btn--prev"
                            data-action="beginner-questionnaire#previous"${isFirst ? ' disabled' : ''}>
                        Back
                    </button>
                    <button type="button" class="tm-quiz__nav-btn tm-quiz__nav-btn--next"
                            data-action="beginner-questionnaire#next"${canAdvance ? '' : ' disabled'}>
                        ${isLast ? 'SEE MY RECOMMENDATION' : 'Next'}
                    </button>
                </div>
            </div>
        `;
    }

    showResults() {
        // Hide the question stage and reveal the server-rendered results.
        this.stageTarget.hidden = true;
        this.stageTarget.innerHTML = '';
        // Fill the results summary with all of the user's answers.
        if (this.hasSummaryTarget) {
            this.summaryTarget.innerHTML = this.crumbChips(this.steps.length);
        }
        if (this.hasResultsTarget) {
            this.resultsTarget.hidden = false;
        }
    }
}
