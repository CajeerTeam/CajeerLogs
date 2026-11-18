plugins {
    kotlin("jvm") version "2.0.0"
}

group = "ru.cajeer.logs"
version = "0.5.10"

repositories { mavenCentral() }
kotlin {
    jvmToolchain(17)
    sourceSets.named("test") { kotlin.srcDir("../../tests/sdk") }
}
tasks.register<JavaExec>("wireTest") {
    dependsOn(tasks.named("testClasses"))
    classpath = sourceSets["test"].runtimeClasspath
    mainClass.set("WireTestKt")
    doFirst { args(providers.gradleProperty("wireUrl").get(), providers.gradleProperty("wireToken").get()) }
}

// Integration test uses a live loopback HTTP server supplied by tests/sdk_async_wire.py.
tasks.register<JavaExec>("asyncWireTest") {
    dependsOn(tasks.named("testClasses"))
    classpath = sourceSets["test"].runtimeClasspath
    mainClass.set("AsyncWireTestKt")
    doFirst { args(providers.gradleProperty("wireUrl").get(), providers.gradleProperty("wireToken").get()) }
}
