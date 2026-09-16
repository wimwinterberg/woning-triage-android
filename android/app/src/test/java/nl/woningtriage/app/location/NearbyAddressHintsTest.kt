package nl.woningtriage.app.location

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class NearbyAddressHintsTest {
    @Test
    fun keepsUniqueDutchHousesAndDropsForeignResults() {
        val hints = NearbyAddressHints.fromPlaces(
            listOf(
                GeocodedPlace(
                    postalCode = "3573SJ",
                    subThoroughfare = "207",
                    countryCode = "NL",
                ),
                GeocodedPlace(
                    postalCode = "3573 SJ",
                    subThoroughfare = "207",
                    countryName = "Nederland",
                ),
                GeocodedPlace(
                    postalCode = "3573 SJ",
                    subThoroughfare = "205",
                    countryCode = "NL",
                ),
                GeocodedPlace(
                    postalCode = "1000",
                    subThoroughfare = "1",
                    countryCode = "BE",
                ),
            ),
        )
        assertEquals(2, hints.size)
        assertEquals("3573 SJ", hints[0].postcode)
        assertEquals(207, hints[0].houseNumber)
        assertEquals(205, hints[1].houseNumber)
    }

    @Test
    fun parsesHouseNumberWithAddition() {
        assertEquals(12 to "A", NearbyAddressHints.parseHouseNumber("12A"))
        assertEquals(12 to "bis", NearbyAddressHints.parseHouseNumber("12-bis"))
        assertEquals(207 to null, NearbyAddressHints.parseHouseNumber("207"))
        assertEquals(null, NearbyAddressHints.parseHouseNumber("Oldenburgerstraat"))
    }

    @Test
    fun ignoresPlacesWithoutAHouseNumber() {
        val hints = NearbyAddressHints.fromPlaces(
            listOf(
                GeocodedPlace(
                    postalCode = "3573 SJ",
                    featureName = "Utrecht",
                    countryCode = "NL",
                ),
            ),
        )
        assertTrue(hints.isEmpty())
    }
}
