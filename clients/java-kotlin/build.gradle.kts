plugins {
    kotlin("jvm") version "2.0.0"
}

group = "ru.cajeer.logs"
version = "0.3.1"

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
