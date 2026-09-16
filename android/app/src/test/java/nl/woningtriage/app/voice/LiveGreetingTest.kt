package nl.woningtriage.app.voice

import org.junit.Assert.assertTrue
import org.junit.Test

class LiveGreetingTest {
    @Test
    fun spokenGreetingIncludesRentalHomeAndQuestion() {
        val spoken = LiveGreeting.spoken("Wat is er aan de hand in uw woning?")
        assertTrue(spoken.contains("huurwoning"))
        assertTrue(spoken.contains("Wat is er aan de hand in uw woning?"))
        assertTrue(LiveGreeting.instructions(spoken).contains("Greet immediately"))
        assertTrue(LiveGreeting.instructions(spoken).contains("Do not switch back to Dutch"))
        assertTrue(!LiveGreeting.instructions(spoken).contains("Speak Dutch."))
        assertTrue(LiveGreeting.commentary(spoken).contains(spoken))
    }
}
