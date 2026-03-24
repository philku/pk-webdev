import { Controller } from '@hotwired/stimulus'

// Interactive terminal easter egg on the homepage.
// Captures keyboard input when the terminal section is clicked,
// renders typed characters, and responds to a set of fun commands.
export default class extends Controller {
    static targets = ['input', 'cursor', 'output', 'prompt', 'hiddenInput']

    // Known commands and their responses
    commands = {
        help: 'Available commands: whoami, status, stack, sudo hire pk',
        whoami: 'visitor@pk-webdev — Welcome!',
        status: 'Available — open for new projects ✓',
        clear: null, // handled separately
        stack: null, // handled separately
    }

    connect() {
        this.buffer = ''
        this.active = false
        this.history = []

        // Click on terminal activates input capture
        this.element.addEventListener('click', () => this.activate())
    }

    activate() {
        if (this.active) {
            this.hiddenInputTarget.focus()
            return
        }
        this.active = true

        // Show the input span next to cursor
        this.inputTarget.classList.remove('hidden')
        this.cursorTarget.classList.add('terminal-cursor-active')

        // Focus hidden input to open mobile keyboard
        this.hiddenInputTarget.focus()

        // Listen for keystrokes (desktop)
        this._onKeydown = this.handleKeydown.bind(this)
        document.addEventListener('keydown', this._onKeydown)

        // Listen for input events (mobile)
        this._onInput = this.handleInput.bind(this)
        this.hiddenInputTarget.addEventListener('input', this._onInput)

        // Handle Enter on mobile (submit via keydown on hidden input)
        this._onHiddenKeydown = (e) => {
            if (e.key === 'Enter') {
                e.preventDefault()
                this.execute()
                this.hiddenInputTarget.value = ''
            }
        }
        this.hiddenInputTarget.addEventListener('keydown', this._onHiddenKeydown)

        // Deactivate when clicking outside
        this._onClickOutside = (e) => {
            if (!this.element.contains(e.target)) this.deactivate()
        }
        setTimeout(() => document.addEventListener('click', this._onClickOutside), 10)
    }

    deactivate() {
        this.active = false
        this.cursorTarget.classList.remove('terminal-cursor-active')
        this.hiddenInputTarget.blur()
        document.removeEventListener('keydown', this._onKeydown)
        document.removeEventListener('click', this._onClickOutside)
        this.hiddenInputTarget.removeEventListener('input', this._onInput)
        this.hiddenInputTarget.removeEventListener('keydown', this._onHiddenKeydown)
    }

    handleInput() {
        // Sync hidden input value to buffer (mobile keyboard input)
        this.buffer = this.hiddenInputTarget.value
        this.render()
    }

    handleKeydown(e) {
        // Ignore modifier combos (Cmd+C etc) except Shift
        if (e.metaKey || e.ctrlKey || e.altKey) return

        if (e.key === 'Enter') {
            e.preventDefault()
            this.execute()
        } else if (e.key === 'Backspace') {
            e.preventDefault()
            this.buffer = this.buffer.slice(0, -1)
            this.render()
        } else if (e.key.length === 1) {
            e.preventDefault()
            this.buffer += e.key
            this.render()
        }
    }

    render() {
        this.inputTarget.textContent = this.buffer
    }

    execute() {
        const cmd = this.buffer.trim().toLowerCase()
        this.buffer = ''
        this.render()

        if (!cmd) return

        // Build the command line that was entered
        const line = this.createLine(cmd)
        this.outputTarget.appendChild(line)

        // Handle special commands
        if (cmd === 'clear') {
            this.outputTarget.innerHTML = ''
            return
        }

        if (cmd === 'stack') {
            const stackSection = document.querySelector('#tech-stack')
            if (stackSection) {
                this.addResponse('Scrolling to Tech Stack...')
                setTimeout(() => stackSection.scrollIntoView({ behavior: 'smooth' }), 300)
            } else {
                this.addResponse('Tech Stack section not found.')
            }
            return
        }

        if (cmd === 'sudo hire pk') {
            this.addResponse('✓ Permission granted. Opening mail client...')
            setTimeout(() => {
                window.location.href = 'mailto:p.kuechau@gmx.de?subject=Einladung%20zum%20Gespr%C3%A4ch'
            }, 1200)
            return
        }

        // Lookup known commands
        const response = this.commands[cmd]
        if (response) {
            this.addResponse(response)
        } else {
            this.addResponse(`zsh: command not found: ${cmd}. Try: help`)
        }
    }

    createLine(cmd) {
        const div = document.createElement('div')
        div.className = 'flex items-center gap-2 mt-2'
        div.innerHTML = `<span class="text-accent-400 text-xs">❯</span><span class="text-white/70">${this.escapeHtml(cmd)}</span>`
        return div
    }

    addResponse(text) {
        const div = document.createElement('div')
        div.className = 'mt-1 text-white/50 pl-5'
        div.textContent = text
        this.outputTarget.appendChild(div)
        this.trimHistory()
    }

    // Keep only the last 2 command+response blocks visible
    trimHistory() {
        const children = [...this.outputTarget.children]
        // Each command is a prompt line, each response is a separate div
        // Count command lines (they have the ❯ prompt)
        const cmdLines = children.filter(el => el.querySelector('.text-accent-400'))
        while (cmdLines.length > 2) {
            // Remove the oldest command line and all elements before the next command
            const oldest = cmdLines.shift()
            let sibling = oldest.nextElementSibling
            // Remove response lines until next command line
            while (sibling && !sibling.querySelector('.text-accent-400')) {
                const next = sibling.nextElementSibling
                sibling.remove()
                sibling = next
            }
            oldest.remove()
        }
    }

    escapeHtml(str) {
        const div = document.createElement('div')
        div.textContent = str
        return div.innerHTML
    }

    disconnect() {
        if (this.active) this.deactivate()
    }
}
